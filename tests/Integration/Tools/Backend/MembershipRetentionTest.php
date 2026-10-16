<?php

namespace Tests\Integration\Tools\Backend;

use App\Events\Banking\{NonPaymentOfMembership,NonPaymentOfMinimumMembership};
use App\Listeners\Membership\{RevokeMembership,RevokeMembershipUnderPaid};
use HMS\Entities\{Role,Banking\Account,Banking\Bank,Banking\MembershipStatusNotification};
use HMS\Repositories\{MetaRepository,UserRepository};
use HMS\Repositories\Banking\{BankRepository,MembershipStatusNotificationRepository};
use HMS\Repositories\Members\BoxRepository;
use HMS\Tools\ToolManager;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;

final class MembershipRetentionTest extends \Tests\Support\BackendLifecycleTestCase
{
    public static function revocations(): array
    {
        return ['nonpayment current'=>[false,Role::MEMBER_CURRENT],'nonpayment young'=>[false,Role::MEMBER_YOUNG],
            'underpayment current'=>[true,Role::MEMBER_CURRENT],'underpayment young'=>[true,Role::MEMBER_YOUNG]];
    }
    #[DataProvider('revocations')]
    public function testPHP07ActualMembershipRevocationRetainsUserGrantAndDropsElevatedRoles(bool $underpaid,string $membership): void
    {
        $this->localAudit();$tool=$this->createTool();$manager=$this->app->make(ToolManager::class);
        $current=Fixtures::role($membership);$ex=Fixtures::role(Role::MEMBER_EX);$account=new Account('TEST-REFERENCE');
        $this->persist($current,$ex,$account);$this->memberEntity->setAccount($account);$this->memberEntity->getRoles()->add($current);$this->persist($this->memberEntity);
        foreach(['USER','INDUCTOR','MAINTAINER'] as $type)$manager->grant($tool,$type,$this->memberEntity);
        $meta=Mockery::mock(MetaRepository::class);$bankRepo=Mockery::mock(BankRepository::class);$boxes=Mockery::mock(BoxRepository::class);
        $meta->shouldReceive('getInt')->with('so_bank_id')->once()->andReturn(1);
        $meta->shouldReceive('getInt')->with('membership_minimum_amount',500)->once()->andReturn(500);
        $bank=(new Bank)->setName('Synthetic')->setSortCode('000000')->setAccountNumber('00000000')->setAccountName('Synthetic');
        $bankRepo->shouldReceive('findOneById')->with(1)->once()->andReturn($bank);
        $boxes->shouldReceive('countInUseByUser')->once()->andReturn(0);
        $notification=new MembershipStatusNotification;
        $notifications=Mockery::mock(MembershipStatusNotificationRepository::class);
        $notifications->shouldReceive('findOutstandingNotificationsByUser')->once()->andReturn([$notification]);
        $notifications->shouldReceive('save')->with(Mockery::on(fn($n)=>$n===$notification && $n->getClearedReason()==='REVOKED'))->once();
        $pending=Mockery::mock(\Illuminate\Mail\PendingMail::class);
        Mail::shouldReceive('to')->once()->andReturn($pending);
        $expectedMail=$underpaid?\App\Mail\Membership\MembershipRevokedDueToUnderPayment::class:\App\Mail\Membership\MembershipRevoked::class;
        $pending->shouldReceive('send')->once()->with(Mockery::on(fn($mail)=>$mail instanceof $expectedMail && $mail->paymentRef==='TEST-REFERENCE' && $mail->accountNo==='00000000'));
        $class=$underpaid?RevokeMembershipUnderPaid::class:RevokeMembership::class;
        $event=$underpaid?new NonPaymentOfMinimumMembership($this->memberEntity):new NonPaymentOfMembership($this->memberEntity);
        (new $class($this->app->make(UserRepository::class),$this->app->make(\HMS\User\Permissions\RoleManager::class),$notifications,$meta,$bankRepo,$boxes))->handle($event);
        $this->em->clear();$user=$this->em->find(\HMS\Entities\User::class,$this->user);
        self::assertTrue($user->hasRoleByName('tools.spike.user'));
        self::assertTrue($user->hasRoleByName(Role::MEMBER_EX));
        foreach([$membership,'tools.spike.inductor','tools.spike.maintainer'] as $name)self::assertFalse($user->hasRoleByName($name),$name);
        self::assertSame('REVOKED',$notification->getClearedReason());
    }
    public function testPHP07NonMemberRevocationDoesNotTouchRolesNotificationsOrMail(): void
    {
        $this->localAudit();$before=$this->rows('SELECT * FROM role_user ORDER BY role_id');
        foreach([RevokeMembership::class,RevokeMembershipUnderPaid::class] as $class){
            $notifications=Mockery::mock(MembershipStatusNotificationRepository::class);
            $meta=Mockery::mock(MetaRepository::class);$banks=Mockery::mock(BankRepository::class);$boxes=Mockery::mock(BoxRepository::class);
            $event=$class===RevokeMembership::class?new NonPaymentOfMembership($this->memberEntity):new NonPaymentOfMinimumMembership($this->memberEntity);
            (new $class($this->app->make(UserRepository::class),$this->app->make(\HMS\User\Permissions\RoleManager::class),$notifications,$meta,$banks,$boxes))->handle($event);
            self::assertSame($before,$this->rows('SELECT * FROM role_user ORDER BY role_id'));
            self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM role_updates'));
        }
    }

}
