<?php

namespace Tests\Integration\Tools\Sql;

use HMS\Entities\Tools\Tool;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;
use Tests\Support\SqlTestCase;

final class StatusAccessTest extends SqlTestCase
{
    public function testSQL01StatusIdentityLatestUsageAndUnknownTool(): void
    {
        $this->execute("UPDATE tools SET status='DISABLED', status_text='Synthetic maintenance' WHERE id=?", [$this->tool]);
        $row = $this->rows('CALL sp_tool_get_status(?)', [$this->tool])[0];
        self::assertSame([$this->tool, 'test_fixture', 'DISABLED', 'Synthetic maintenance', '<unknown>', '<unknown>'],
            [(int) $row['tool_id'], $row['tool_name'], $row['tool_status'], $row['tool_status_text'], $row['usage_start'], $row['usage_end']]);
        $this->usage(30, 'CHARGED');
        $this->usage(60, 'CHARGED');
        $row = $this->rows('CALL sp_tool_get_status(-1)')[0];
        self::assertSame(self::NOW, $row['usage_start']);
        self::assertSame('2025-02-15 12:01:00', $row['usage_end']);
        self::assertSame([], $this->rows('CALL sp_tool_get_status(999999)'));
        $this->execute("UPDATE tools SET status='FREE' WHERE id=?", [$this->tool]);
        self::assertSame('FREE', $this->rows('CALL sp_tool_get_status(?)', [$this->tool])[0]['tool_status']);
    }

    public static function deniedCards(): array
    {
        return ['unknown' => ['UNKNOWN', null, true, 'Unknown card'],
            'lost' => ['TEST-MEMBER', 30, true, 'Unknown card'],
            'destroyed/expired state' => ['TEST-MEMBER', 20, true, 'Unknown card'],
            'inactive' => ['TEST-MEMBER', 0, true, 'Unknown card'],
            'no general permission' => ['TEST-MEMBER', 10, false, 'No permission']];
    }

    #[DataProvider('deniedCards')]
    public function testSQL02RejectedCardsCannotChangeUsageOrMoney(string $card, ?int $state, bool $permission, string $message): void
    {
        if ($state !== null) $this->execute('UPDATE rfid_tags SET state=?', [$state]);
        if (! $permission) $this->execute('DELETE FROM role_user WHERE user_id=?', [$this->user]);
        $before = $this->snapshot();
        $result = $this->signOn(card: $card);
        self::assertSame(0, (int) $result['access']);
        self::assertSame($message, trim($result['message']));
        self::assertSame($before, $this->snapshot());
    }

    public function testSQL02UnknownToolAndMissingProfileRejectWithoutWrites(): void
    {
        $before = $this->snapshot();
        self::assertSame('Tool not conf.', trim($this->signOn('missing')['message']));
        self::assertSame($before, $this->snapshot());
        $this->execute('DELETE FROM profile WHERE user_id=?', [$this->user]);
        self::assertSame('Unknown card', trim($this->signOn()['message']));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM tool_usages'));
    }

    public static function accessPermissions(): array
    {
        return ['unrestricted' => ['UNRESTRICTED', null, 1, 'U'],
            'restricted uninducted' => ['RESTRICTED', null, 0, 'Not inducted'],
            'inducted' => ['RESTRICTED', 'use', 1, 'U'],
            'inductor' => ['RESTRICTED', 'induct', 1, 'I'],
            'maintainer' => ['RESTRICTED', 'maintain', 1, 'M']];
    }

    #[DataProvider('accessPermissions')]
    public function testSQL03RealPermissionJoinsControlRestrictedAccess(string $restriction, ?string $permission, int $allowed, string $message): void
    {
        $this->execute('UPDATE tools SET restrictions=? WHERE id=?', [$restriction, $this->tool]);
        if ($permission) $this->grant($this->user, 'tools.test_fixture.'.$permission);
        $before = $this->snapshot();
        $result = $this->signOn();
        self::assertSame($allowed, (int) $result['access']);
        self::assertSame($message, trim($result['message']));
        if (! $allowed) self::assertSame($before, $this->snapshot());
        else {
            self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM tool_usages WHERE user_id=? AND tool_id=? AND status=\'IN_PROGRESS\'', [$this->user, $this->tool]));
            self::assertSame('IN_USE', $this->scalar('SELECT status FROM tools WHERE id=?', [$this->tool]));
            self::assertSame('IN_USE', $this->rows('CALL sp_tool_get_status(?)', [$this->tool])[0]['tool_status']);
        }
    }

    public function testSQL03PermissionFunctionCaseInsensitiveAndUserScoped(): void
    {
        $other = $this->member('other');
        self::assertSame(1, (int) $this->scalar("SELECT fn_check_permission(?, 'TOOLS.USE')", [$this->user]));
        self::assertSame(0, (int) $this->scalar("SELECT fn_check_permission(?, 'tools.use')", [$other]));
        self::assertSame(0, (int) $this->scalar("SELECT fn_check_permission(999999, 'tools.use')"));
        self::assertSame(0, (int) $this->scalar("SELECT fn_check_permission(?, 'missing')", [$this->user]));
    }

    public static function availability(): array
    {
        return ['disabled ordinary' => [true, false, false], 'disabled maintainer' => [true, false, true],
            'maintenance ordinary' => [false, true, false], 'maintenance maintainer' => [false, true, true]];
    }

    #[DataProvider('availability')]
    public function testSQL04DisabledAndMaintenanceMaintainerException(bool $disabled, bool $booking, bool $maintainer): void
    {
        if ($disabled) $this->execute("UPDATE tools SET status='DISABLED', pph=3600 WHERE id=?", [$this->tool]);
        if ($booking) $this->booking();
        if ($maintainer) $this->grant($this->user, 'tools.test_fixture.maintain');
        $before = $this->snapshot();
        $result = $this->signOn();
        self::assertSame($maintainer ? 1 : 0, (int) $result['access']);
        if (! $maintainer) {
            self::assertSame('Out of service', trim($result['message']));
            self::assertSame($before, $this->snapshot());
        } else {
            self::assertSame($disabled ? 'DISABLED' : 'IN_USE', $this->scalar('SELECT status FROM tools WHERE id=?', [$this->tool]));
            $this->signOff(active: 5);
            self::assertSame($disabled ? 'DISABLED' : 'FREE', $this->scalar('SELECT status FROM tools WHERE id=?', [$this->tool]));
            self::assertSame(0, (int) $this->scalar('SELECT balance FROM profile WHERE user_id=?', [$this->user]));
        }
    }

    public function testSQL05SignOnOffPersistsAndCrossReadsWithDoctrineWithoutTouchingOtherTools(): void
    {
        $em = $this->app['em'];
        $second = Fixtures::tool('other')->setStatus('IN_USE');
        $em->persist($second); $em->flush();
        $otherUser = $this->member('other');
        $otherUsage = $this->usage(status: 'IN_PROGRESS', user: $otherUser, tool: $second->getId());
        self::assertSame(1, (int) $this->signOn()['access']);
        $id = (int) $this->scalar('SELECT id FROM tool_usages WHERE tool_id=?', [$this->tool]);
        self::assertSame('No charge for tool', $this->signOff(active: 17));
        $row = $this->rows('SELECT * FROM tool_usages WHERE id=?', [$id])[0];
        self::assertSame(['CHARGED', 0, 17], [$row['status'], (int) $row['duration'], (int) $row['active_time']]);
        $em->clear();
        self::assertSame('FREE', $em->find(Tool::class, $this->tool)->getStatus());
        self::assertSame('IN_PROGRESS', $this->scalar('SELECT status FROM tool_usages WHERE id=?', [$otherUsage]));
        self::assertSame('', $this->signOff(), 'Repeat/no active usage is a no-op');
        self::assertSame('Tool not configured', $this->signOff('missing'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM transactions'));
    }

    public function testSQL02RfidHelpersReturnActiveIdentityAndRejectInactiveOrUnknown(): void
    {
        foreach (['UNKNOWN' => 'Unknown card', 'Unknown Card Type' => 'Unknown Card Type'] as $card => $message) {
            $this->rows('CALL sp_check_rfid(?, @member, @error)', [$card]);
            self::assertSame([null, $message], array_values($this->rows('SELECT @member, @error')[0]));
        }
        $this->rows("CALL sp_check_rfid('TEST-MEMBER', @member, @error)");
        self::assertSame([$this->user, ''], [(int) $this->scalar('SELECT @member'), $this->scalar('SELECT @error')]);
        self::assertSame(self::NOW, $this->scalar('SELECT last_used FROM rfid_tags WHERE rfid_serial=\'TEST-MEMBER\''));
        $this->db->exec('SET @username=NULL, @balance=NULL, @error=NULL');
        $this->rows("CALL sp_get_details_from_rfid('TEST-MEMBER  ', @username, @balance, @error)");
        self::assertSame(['test_member', 0], [$this->scalar('SELECT @username'), (int) $this->scalar('SELECT @balance')]);
        $this->execute('UPDATE rfid_tags SET state=20');
        $this->rows("CALL sp_check_rfid('TEST-MEMBER', @member, @error)");
        self::assertSame([null, 'Inactive card'], array_values($this->rows('SELECT @member, @error')[0]));
    }
    public function testSQL05RepeatedSignOnMustNotCreateConcurrentUsage(): void
    {
        self::assertSame(1, (int) $this->signOn()['access']);
        $second = $this->signOn();
        self::assertSame(0, (int) $second['access'], 'A second sign-on while in use should be rejected');
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM tool_usages'));
    }

    public function testSQL05SignOffClosesAllLegacyOpenRowsAndAllocatesActiveTimeOnce(): void
    {
        $this->usage(); $this->usage();
        $this->execute("UPDATE tools SET status='IN_USE' WHERE id=?", [$this->tool]);
        self::assertSame('No charge for tool', $this->signOff(active: 19));
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM tool_usages WHERE status='CHARGED'"));
        self::assertSame(19, (int) $this->scalar('SELECT SUM(active_time) FROM tool_usages'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM tool_usages WHERE active_time=19'));
    }

    public function testSQL05PaidSignOnOffUsesElapsedSqlTimeAndRecordedActiveTime(): void
    {
        $this->grant($this->user, 'snackspace.purchase.creditOnly');
        $this->execute('UPDATE profile SET balance=100 WHERE user_id=?', [$this->user]);
        $this->execute('UPDATE tools SET pph=3600 WHERE id=?', [$this->tool]);
        self::assertSame(1, (int) $this->signOn()['access']);
        $this->db->exec('SET timestamp = 1739620860');
        self::assertSame('', $this->signOff(active: 7));
        self::assertSame([60, 7, 'CHARGED'], array_values($this->rows('SELECT duration, active_time, status FROM tool_usages')[0]));
        self::assertSame(40, (int) $this->scalar('SELECT balance FROM profile WHERE user_id=?', [$this->user]));
        self::assertSame(-60, (int) $this->scalar('SELECT amount FROM transactions'));
        $before = $this->snapshot();
        self::assertSame('', $this->signOff(active: 7));
        self::assertSame($before, $this->snapshot());
    }

}
