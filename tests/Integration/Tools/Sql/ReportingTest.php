<?php

namespace Tests\Integration\Tools\Sql;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;
use Tests\Support\SqlTestCase;

final class ReportingTest extends SqlTestCase
{
    public static function maintenanceBoundaries(): array
    {
        return ['inside' => [-60, 60, 'MAINTENANCE', 1], 'start inclusive' => [0, 60, 'MAINTENANCE', 1],
            'end inclusive' => [-60, 0, 'MAINTENANCE', 1], 'point inclusive' => [0, 0, 'MAINTENANCE', 1],
            'future' => [1, 60, 'MAINTENANCE', 0], 'past' => [-60, -1, 'MAINTENANCE', 0],
            'normal' => [-60, 60, 'NORMAL', 0], 'induction' => [-60, 60, 'INDUCTION', 0]];
    }

    #[DataProvider('maintenanceBoundaries')]
    public function testSQL11MaintenanceFunctionExactUtcInterval(int $start, int $end, string $type, int $expected): void
    {
        $this->booking($start, $end, $type);
        self::assertSame($expected, (int) $this->scalar('SELECT fn_tool_in_maintenance_slot(?)', [$this->tool]));
        self::assertSame(0, (int) $this->scalar('SELECT fn_tool_in_maintenance_slot(999999)'));
    }

    public function testSQL11BookingRetrievalFiltersToolAndCalendarReturnsConfiguredIdentity(): void
    {
        $other = Fixtures::tool('other'); $this->app['em']->persist($other); $this->app['em']->flush();
        $first = $this->booking(-120, -60, 'NORMAL');
        $second = $this->booking(60, 120, 'INDUCTION');
        $this->booking(tool: $other->getId());
        $rows = $this->rows('CALL sp_tool_get_bookings(?)', [$this->tool]);
        self::assertEqualsCanonicalizing([$first, $second], array_map('intval', array_column($rows, 'id')));
        self::assertSame(['test_member'], array_values(array_unique(array_column($rows, 'username'))));
        self::assertSame([], $this->rows('CALL sp_tool_get_bookings(999999)'));
        $rows = $this->rows('CALL sp_tool_get_calendars(-1)');
        self::assertEqualsCanonicalizing(['test_fixture', 'test_other'], array_column($rows, 'tool_name'));
        self::assertSame(['test_fixture'], array_column($this->rows('CALL sp_tool_get_calendars(?)', [$this->tool]), 'tool_name'));
        self::assertSame([], $this->rows('CALL sp_tool_get_calendars(999999)'));
    }

    public function testSQL11GoogleChannelUpsertIsLocalDataOnly(): void
    {
        // This routine only stores a row. No Google client/subscriber or job is invoked.
        $this->rows("CALL sp_tool_set_google_channel(?, 'synthetic-resource', 'synthetic-token', 'synthetic-channel', 1739620800)", [$this->tool]);
        $this->rows("CALL sp_tool_set_google_channel(?, 'replacement', 'replacement-token', 'synthetic-channel', 1739620860)", [$this->tool]);
        $rows = $this->rows('SELECT tool_id, resource_id, channel_token, channel_id, channel_expiration FROM tl_google_notifications');
        self::assertCount(1, $rows);
        self::assertSame([$this->tool, 'replacement', 'replacement-token', 'synthetic-channel', '2025-02-15 12:01:00'], array_values($rows[0]));
    }

    public function testSQL12UsageViewAgreesWithSyntheticSourceRows(): void
    {
        $other = $this->member('other');
        $this->usage(90, 'CHARGED');
        $this->usage(30, 'COMPLETE', $other);
        $rows = $this->rows('SELECT * FROM vw_tool_usage ORDER BY user_id');
        self::assertCount(2, $rows);
        self::assertSame([$this->user, $other], array_map('intval', array_column($rows, 'user_id')));
        self::assertSame(['test_fixture', 'test_fixture'], array_column($rows, 'tool_name'));
        self::assertSame(['member@example.invalid', 'other@example.invalid'], array_column($rows, 'user_email'));
        self::assertSame([1.5, 0.5], array_map('floatval', array_column($rows, 'usage_mins')));
        self::assertSame(['15/02/2025 12:00:00', '15/02/2025 12:00:00'], array_column($rows, 'time'));
    }

    public function testSQL12MemberSummaryCountsToolUsageButExcludesNonMembers(): void
    {
        $this->grant($this->user, 'test.membership.marker');
        $role = Fixtures::role('member.current');
        $this->app['em']->persist($role); $this->app['em']->flush();
        $this->execute('INSERT INTO role_user (user_id, role_id) VALUES (?, ?)', [$this->user, $role->getId()]);
        $other = $this->member('other');
        $this->usage(60, 'CHARGED');
        $this->usage(60, 'CHARGED', $other);
        // This view uses NOW(), not UTC_TIMESTAMP(). Freeze its own read session too.
        $row = $this->rows('SELECT * FROM vw_member_stats')[0];
        foreach (['last_day', 'last_week', 'last_month', 'last_quarter', 'last_six_month', 'last_year', 'anytime', 'total_current_members'] as $key) {
            self::assertSame(1, (int) $row[$key], $key);
        }
    }

    public function testSQL12InductorMaintainerViewPreservesMemberAndRoleIdentity(): void
    {
        $em = $this->app['em'];
        $member = Fixtures::role('member.current');
        $member->setDisplayName('Synthetic current member');
        $teacher = Fixtures::role('tools.test_fixture.inductor');
        $teacher->setDisplayName('Tool: test_fixture Inductor');
        foreach ([$member, $teacher] as $role) $em->persist($role);
        $em->flush();
        foreach ([$member, $teacher] as $role) $this->execute('INSERT INTO role_user (user_id, role_id) VALUES (?, ?)', [$this->user, $role->getId()]);
        $row = $this->rows('SELECT * FROM vw_tool_inductors_maintainers')[0];
        self::assertSame(['test_fixture', 'Inductor', 'Synthetic Member', 'Synthetic current member', 'member@example.invalid'], array_values($row));
    }

    public function testSQL13NormalReservationIsAdvisoryForSignOn(): void
    {
        $other = $this->member('other');
        $this->booking(type: 'NORMAL', user: $other);
        // Characterize the existing SQL contract; exclusivity is a separate product decision.
        self::assertSame(1, (int) $this->signOn()['access']);
        self::assertSame(0, (int) $this->scalar('SELECT fn_tool_in_maintenance_slot(?)', [$this->tool]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM tool_usages WHERE user_id=?', [$this->user]));
    }
    public function testSQL11CancelledMaintenanceRemovalDoesNotBlockUse(): void
    {
        $id = $this->booking();
        self::assertSame(1, (int) $this->scalar('SELECT fn_tool_in_maintenance_slot(?)', [$this->tool]));
        $this->execute('DELETE FROM bookings WHERE id=?', [$id]);
        self::assertSame(0, (int) $this->scalar('SELECT fn_tool_in_maintenance_slot(?)', [$this->tool]), 'Cancelled maintenance should not count');
    }

    public function testSQL12ToolUserSummaryQueryAndTotals(): void
    {
        $em = $this->app['em'];
        $member = Fixtures::role('member.current');
        $toolRole = Fixtures::role('tools.test_fixture.user');
        $toolRole->setDisplayName('Tool: test_fixture User');
        foreach ([$member, $toolRole] as $role) $em->persist($role);
        $em->flush();
        foreach ([$member, $toolRole] as $role) $this->execute('INSERT INTO role_user (user_id, role_id) VALUES (?, ?)', [$this->user, $role->getId()]);
        $this->usage(3600, 'CHARGED');
        $row = $this->rows('SELECT * FROM vw_tool_users')[0];
        self::assertSame($this->tool, (int) $row['tool_id']);
        self::assertSame(1, (int) $row['session_count']);
        self::assertSame(1.0, (float) $row['session_duration_total_hours']);
    }

    public function testSQL12LegacyLaserSummaryUsesOnlyPinnedLegacyIdentities(): void
    {
        // Fixed production-era IDs are a legacy SQL contract, not references to live tools.
        // Recreated schema starts this synthetic fixture at ID 1.
        self::assertSame(1, $this->tool);
        $this->usage(3600, 'CHARGED');
        $this->usage(1800, 'COMPLETE');
        $row = $this->rows('SELECT * FROM vw_laser_usage')[0];
        self::assertSame([2025, 'February', '01:30:00', '01:00:00', '3.00', 1],
            [(int) $row['year'], $row['month'], $row['total_time'], $row['charged_time'], $row['charged_income'], (int) $row['distinct_users']]);
    }

}
