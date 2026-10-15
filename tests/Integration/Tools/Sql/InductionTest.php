<?php

namespace Tests\Integration\Tools\Sql;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;
use Tests\Support\SqlTestCase;

final class InductionTest extends SqlTestCase
{
    private function induct(string $tool = 'test_fixture', string $teacher = 'TEST-TEACHER', string $student = 'TEST-MEMBER'): array
    {
        $this->db->exec('SET @result=NULL, @message=NULL');
        $this->rows('CALL sp_tool_induct(?, ?, ?, @result, @message)', [$tool, $teacher, $student]);
        return [(int) $this->scalar('SELECT @result'), $this->scalar('SELECT @message')];
    }

    private function userRole(): int
    {
        $permission = Fixtures::permission();
        $role = Fixtures::role('tools.test_fixture.user', [$permission]);
        $this->app['em']->persist($permission);
        $this->app['em']->persist($role);
        $this->app['em']->flush();
        return $role->getId();
    }

    public static function rates(): array { return ['free' => [0], 'chargeable' => [3600]]; }

    #[DataProvider('rates')]
    public function testSQL10InductionGrantsAuditsAndAwardsExactlyOnce(int $rate): void
    {
        $teacher = $this->member('teacher', ['tools.test_fixture.induct']);
        $role = $this->userRole();
        $this->execute('UPDATE tools SET pph=? WHERE id=?', [$rate, $this->tool]);
        self::assertSame([0, ''], $this->induct());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM role_user WHERE user_id=? AND role_id=?', [$this->user, $role]));
        $audit = $this->rows('SELECT * FROM role_updates')[0];
        self::assertSame([$this->user, $role, $teacher], [(int) $audit['user_id'], (int) $audit['added_role_id'], (int) $audit['update_by_user_id']]);
        self::assertSame(self::NOW, $audit['created_at']);
        self::assertSame(1, (int) $this->scalar("SELECT fn_check_permission(?, 'tools.test_fixture.use')", [$this->user]));
        $usages = $this->rows('SELECT * FROM tool_usages');
        self::assertCount($rate > 0 ? 1 : 0, $usages);
        if ($rate > 0) {
            self::assertSame([-3600, 'COMPLETE', $this->user, $this->tool], [(int) $usages[0]['duration'], $usages[0]['status'], (int) $usages[0]['user_id'], (int) $usages[0]['tool_id']]);
        }
        $before = $this->snapshot();
        self::assertSame([0, 'Already inducted'], $this->induct());
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM role_updates'));
    }

    public static function invalidInductions(): array
    {
        return ['unknown tool' => ['missing', 'TEST-TEACHER', 'TEST-MEMBER', true, true, 'Tool not configured'],
            'unknown teacher' => ['test_fixture', 'UNKNOWN', 'TEST-MEMBER', true, true, 'Access denied    (NI)'],
            'teacher lacks permission' => ['test_fixture', 'TEST-TEACHER', 'TEST-MEMBER', false, true, 'Access denied    (NI)'],
            'unknown student' => ['test_fixture', 'TEST-TEACHER', 'UNKNOWN', true, true, 'Bad card/No perm.'],
            'student lacks general permission' => ['test_fixture', 'TEST-TEACHER', 'TEST-MEMBER', true, false, 'Bad card/No perm.'],
            'inactive teacher' => ['test_fixture', 'TEST-TEACHER', 'TEST-MEMBER', true, true, 'Access denied    (NI)', 'TEST-TEACHER'],
            'inactive student' => ['test_fixture', 'TEST-TEACHER', 'TEST-MEMBER', true, true, 'Bad card/No perm.', 'TEST-MEMBER']];
    }

    #[DataProvider('invalidInductions')]
    public function testSQL10RejectedInductionHasNoGrantAuditOrCredit(string $tool, string $teacherCard, string $studentCard, bool $permission, bool $studentPermission, string $message, ?string $inactive = null): void
    {
        $this->member('teacher', $permission ? ['tools.test_fixture.induct'] : []);
        $this->userRole();
        if (! $studentPermission) $this->execute('DELETE FROM role_user WHERE user_id=?', [$this->user]);
        if ($inactive) $this->execute('UPDATE rfid_tags SET state=30 WHERE rfid_serial=?', [$inactive]);
        $before = $this->snapshot();
        $grants = $this->rows('SELECT * FROM role_user ORDER BY user_id, role_id');
        self::assertSame([-1, $message], $this->induct($tool, $teacherCard, $studentCard));
        self::assertSame($before, $this->snapshot());
        self::assertSame($grants, $this->rows('SELECT * FROM role_user ORDER BY user_id, role_id'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM role_updates'));
    }

    public function testSQL10MissingRoleRollsBackWithoutPartialGrantOrAward(): void
    {
        $this->member('teacher', ['tools.test_fixture.induct']);
        $before = $this->snapshot();
        [$code, $message] = $this->induct();
        self::assertSame(-1, $code);
        self::assertStringContainsString('unexpected number of rows inserted into role_user: 0', $message);
        self::assertSame($before, $this->snapshot());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM role_updates'));
        self::assertFalse($this->db->inTransaction());
    }

    public function testSQL10DatabaseErrorRollsBackRoleAndAudit(): void
    {
        $this->member('teacher', ['tools.test_fixture.induct']);
        $this->userRole();
        // A local test-owned SQL fault, removed by the next case's schema recreation.
        $this->db->exec("CREATE TRIGGER test_reject_audit BEFORE INSERT ON role_updates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit rejection'");
        $grants = $this->rows('SELECT * FROM role_user ORDER BY user_id, role_id');
        [$code, $message] = $this->induct();
        self::assertSame(-1, $code);
        self::assertStringContainsString('transaction rollback!', $message);
        self::assertStringContainsString('synthetic audit rejection', $message);
        self::assertSame($grants, $this->rows('SELECT * FROM role_user ORDER BY user_id, role_id'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM role_updates'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM tool_usages'));
    }
}
