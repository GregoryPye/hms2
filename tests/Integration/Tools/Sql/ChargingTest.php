<?php

namespace Tests\Integration\Tools\Sql;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;
use Tests\Support\SqlTestCase;

final class ChargingTest extends SqlTestCase
{
    public static function prices(): array
    {
        return ['zero rate' => [0, 3600, 7, 0, []], 'one hour' => [3600, 3600, 7, -3600, [-3600]],
            'elapsed not active' => [3600, 60, 1, -60, [-60]], 'round down' => [100, 17, 17, 0, [0]],
            'round up' => [100, 19, 0, -1, [-1]], 'zero elapsed' => [3600, 0, 0, 0, []]];
    }

    #[DataProvider('prices')]
    public function testSQL07RatesElapsedTimeIntegerRoundingAndPersistedMoney(int $rate, int $seconds, int $active, int $amount, array $transactions): void
    {
        $this->execute('UPDATE tools SET pph=? WHERE id=?', [$rate, $this->tool]);
        $this->execute('UPDATE profile SET balance=10000 WHERE user_id=?', [$this->user]);
        $id = $this->usage();
        $message = $this->charge($id, $seconds, $active);
        self::assertSame($rate === 0 ? 'No charge for tool' : '', $message);
        $row = $this->rows('SELECT * FROM tool_usages WHERE id=?', [$id])[0];
        self::assertSame([$seconds, $active], [(int) $row['duration'], (int) $row['active_time']]);
        self::assertSame($rate > 0 && $seconds === 0 ? 'COMPLETE' : 'CHARGED', $row['status']);
        self::assertSame(10000 + $amount, (int) $this->scalar('SELECT balance FROM profile WHERE user_id=?', [$this->user]));
        self::assertSame($transactions, array_map('intval', array_column($this->rows('SELECT amount FROM transactions ORDER BY id'), 'amount')));
        foreach ($this->rows('SELECT * FROM transactions') as $transaction) {
            self::assertSame([$this->user, 'TOOL', 'COMPLETE'], [(int) $transaction['user_id'], $transaction['transaction_type'], $transaction['transaction_status']]);
            self::assertStringContainsString('test_fixture', $transaction['transaction_desc']);
        }
    }

    public static function pledgedTime(): array
    {
        return ['none' => [0, 60, -60, 0, 'CHARGED'], 'partial' => [-30, 60, -30, 0, 'CHARGED'],
            'exact' => [-60, 60, 0, 0, 'COMPLETE'], 'remaining' => [-120, 60, 0, -60, 'COMPLETE'],
            'accumulated positive' => [30, 60, -90, 0, 'CHARGED']];
    }

    #[DataProvider('pledgedTime')]
    public function testSQL06PledgedDurationConsumptionAndMessages(int $prior, int $seconds, int $amount, int $remaining, string $status): void
    {
        $this->execute('UPDATE tools SET pph=3600 WHERE id=?', [$this->tool]);
        if ($prior) $this->usage($prior, 'COMPLETE');
        $id = $this->usage();
        self::assertSame('', $this->charge($id, $seconds));
        self::assertSame($amount, (int) $this->scalar('SELECT balance FROM profile WHERE user_id=?', [$this->user]));
        self::assertSame($remaining, (int) $this->scalar("SELECT COALESCE(SUM(duration),0) FROM tool_usages WHERE user_id=? AND tool_id=? AND status='COMPLETE'", [$this->user, $this->tool]));
        self::assertSame($status, $this->scalar('SELECT status FROM tool_usages WHERE id=?', [$id]));
        $this->rows("CALL sp_tool_pledged_remain('test_fixture', ?, @message)", [$this->user]);
        self::assertSame($remaining < 0 ? "test_member\nRemain  00:01:00" : "test_member\n", $this->scalar('SELECT @message'));
        self::assertSame($amount, (int) $this->scalar('SELECT COALESCE(SUM(amount),0) FROM transactions'));
        if ($prior === -30) self::assertSame([0, -30], array_map('intval', array_column($this->rows('SELECT amount FROM transactions ORDER BY id'), 'amount')));
    }

    public static function credit(): array
    {
        return ['no purchase permission' => [null, 100, 0, 0, 0, 'Not permitted'],
            'credit only positive' => ['snackspace.purchase.creditOnly', 1, 0, 0, 1, 'U'],
            'credit only zero' => ['snackspace.purchase.creditOnly', 0, 0, 0, 0, 'Out of credit'],
            'credit only negative' => ['snackspace.purchase.creditOnly', -1, 100, 0, 0, 'Out of credit'],
            'debt just inside limit' => ['snackspace.purchase', -99, 100, 0, 1, 'U'],
            'debt at limit' => ['snackspace.purchase', -100, 100, 0, 0, 'Out of credit'],
            'debt below limit' => ['snackspace.purchase', -101, 100, 0, 0, 'Out of credit'],
            'pledged bypass' => [null, 0, 0, -1, 1, 'U'],
            'positive duration is not credit' => [null, 0, 0, 1, 0, 'Not permitted']];
    }

    #[DataProvider('credit')]
    public function testSQL08SignOnCreditPermissionsAndExactLimit(?string $permission, int $balance, int $limit, int $pledge, int $allowed, string $message): void
    {
        $this->execute('UPDATE tools SET pph=3600 WHERE id=?', [$this->tool]);
        $this->execute('UPDATE profile SET balance=?, credit_limit=? WHERE user_id=?', [$balance, $limit, $this->user]);
        if ($permission) $this->grant($this->user, $permission);
        if ($pledge) $this->usage($pledge, 'COMPLETE');
        $before = $this->snapshot();
        $result = $this->signOn();
        self::assertSame([$allowed, $message], [(int) $result['access'], trim($result['message'])]);
        if (! $allowed) self::assertSame($before, $this->snapshot());
        self::assertSame($balance, (int) $this->scalar('SELECT balance FROM profile WHERE user_id=?', [$this->user]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM transactions'));
    }

    public function testSQL06PledgedCreditCannotLeakBetweenUsersOrTools(): void
    {
        $other = $this->member('other');
        $tool = Fixtures::tool('other'); $this->app['em']->persist($tool); $this->app['em']->flush();
        $this->execute('UPDATE tools SET pph=3600 WHERE id=?', [$this->tool]);
        $this->usage(-3600, 'COMPLETE', $other);
        $this->usage(-3600, 'COMPLETE', $this->user, $tool->getId());
        self::assertSame('Not permitted', trim($this->signOn()['message']));
        $id = $this->usage();
        self::assertSame('', $this->charge($id, 60));
        self::assertSame(-60, (int) $this->scalar('SELECT balance FROM profile WHERE user_id=?', [$this->user]));
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM tool_usages WHERE status='COMPLETE' AND duration=-3600"));
    }

    public function testSQL08TransactionOverrideAndDisabledMaintainerRetainPledgedTime(): void
    {
        $this->grant($this->user, 'tools.test_fixture.maintain');
        $this->execute("UPDATE tools SET pph=3600, status='DISABLED', transaction_type_override='TESTTOOL' WHERE id=?", [$this->tool]);
        $pledge = $this->usage(-3600, 'COMPLETE');
        $id = $this->usage();
        self::assertSame('', $this->charge($id, 60, 8));
        self::assertSame(0, (int) $this->scalar('SELECT balance FROM profile WHERE user_id=?', [$this->user]));
        self::assertSame('CHARGED', $this->scalar('SELECT status FROM tool_usages WHERE id=?', [$id]));
        self::assertSame([-3600, 'COMPLETE'], array_values($this->rows('SELECT duration, status FROM tool_usages WHERE id=?', [$pledge])[0]));
        $transactions = $this->rows('SELECT * FROM transactions');
        self::assertCount(1, $transactions);
        self::assertSame('TESTTOOL', $transactions[0]['transaction_type']);
        self::assertSame(0, (int) $transactions[0]['amount']);
        self::assertStringContainsString('(maintenance)', $transactions[0]['transaction_desc']);
    }

    public function testSQL09RepeatedChargeRejectedAndOtherRecordsUnchanged(): void
    {
        $this->execute('UPDATE tools SET pph=3600 WHERE id=?', [$this->tool]);
        $id = $this->usage();
        self::assertSame('', $this->charge($id, 60));
        $before = $this->snapshot();
        self::assertSame('Incorrect status: not IN_PROGRESS or DISABLED', $this->charge($id, 120));
        self::assertSame($before, $this->snapshot());
        self::assertFalse($this->db->inTransaction());
    }

    public function testSQL09InvalidUsageReturnsRollbackWithoutMutation(): void
    {
        $before = $this->snapshot();
        self::assertStringStartsWith('ROLLBACK:', $this->charge(999999, 60));
        self::assertSame($before, $this->snapshot());
        self::assertFalse($this->db->inTransaction());
    }

    public function testSQL09SqlFaultBeforeFinancialWriteRollsBackUsage(): void
    {
        $this->execute('UPDATE tools SET pph=3600 WHERE id=?', [$this->tool]);
        $id = $this->usage();
        $this->db->exec("CREATE TRIGGER test_reject_usage BEFORE UPDATE ON tool_usages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic usage rejection'");
        $before = $this->snapshot();
        self::assertStringContainsString('ROLLBACK: synthetic usage rejection', $this->charge($id, 60));
        self::assertSame($before, $this->snapshot());
        self::assertFalse($this->db->inTransaction());
    }

    public function testSQL08PaidOverrideAffectsRecordedTransaction(): void
    {
        $this->execute("UPDATE tools SET pph=3600, transaction_type_override='TESTTOOL' WHERE id=?", [$this->tool]);
        self::assertSame('', $this->charge($this->usage(), 60));
        self::assertSame(['TESTTOOL', -60], array_values($this->rows('SELECT transaction_type, amount FROM transactions')[0]));
    }
    public function testSQL09FinancialInsertFailureShouldRollBackUsageCompletion(): void
    {
        $this->execute('UPDATE tools SET pph=3600 WHERE id=?', [$this->tool]);
        $id = $this->usage();
        $this->db->exec("CREATE TRIGGER test_reject_transaction BEFORE INSERT ON transactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic financial rejection'");
        $before = $this->snapshot();
        self::assertStringContainsString('rollback', strtolower($this->charge($id, 60)));
        self::assertSame($before, $this->snapshot(), 'Failed charging should not leave usage completed');
    }

    public function testSQL09SecondFinancialInsertFailureShouldNotCommitFirstPart(): void
    {
        $this->execute('UPDATE tools SET pph=3600 WHERE id=?', [$this->tool]);
        $this->usage(-30, 'COMPLETE');
        $id = $this->usage();
        $this->db->exec("CREATE TRIGGER test_reject_paid_part BEFORE INSERT ON transactions FOR EACH ROW BEGIN IF NEW.amount < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic paid-part rejection'; END IF; END");
        $before = $this->snapshot();
        self::assertStringContainsString('rollback', strtolower($this->charge($id, 60)));
        self::assertSame($before, $this->snapshot(), 'Mixed charging must be atomic across both transaction records');
    }

    public function testSQL09MissingFinancialProfileRejectsWithoutMutation(): void
    {
        $this->execute('UPDATE tools SET pph=3600 WHERE id=?', [$this->tool]);
        $id = $this->usage();
        $this->execute('DELETE FROM profile WHERE user_id=?', [$this->user]);
        $before = $this->snapshot();
        self::assertSame('Invalid member_id', $this->charge($id, 60));
        self::assertSame($before, $this->snapshot());
        self::assertFalse($this->db->inTransaction());
    }

    public function testSQL07RealPaymentAllocationLinksToolPurchaseAndUpdatesBalanceOnce(): void
    {
        $this->execute('UPDATE tools SET pph=3600 WHERE id=?', [$this->tool]);
        $this->rows("CALL sp_transaction_log(?, 100, 'TESTPAY', 'COMPLETE', 'Synthetic funding', NULL, @payment, @message)", [$this->user]);
        self::assertSame('', $this->scalar('SELECT @message'));
        $payment = (int) $this->scalar('SELECT @payment');
        self::assertSame('', $this->charge($this->usage(), 60));
        $purchase = (int) $this->scalar("SELECT id FROM transactions WHERE transaction_type='TOOL'");
        self::assertSame(40, (int) $this->scalar('SELECT balance FROM profile WHERE user_id=?', [$this->user]));
        self::assertSame([$purchase, $payment, 60], array_map('intval', array_values($this->rows('SELECT transaction_id_purchase, transaction_id_payment, amount FROM purchase_payment')[0])));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM transactions'));
        self::assertSame(40, (int) $this->scalar('SELECT SUM(amount) FROM transactions WHERE user_id=?', [$this->user]));
    }

}
