<?php
namespace Tests\Feature\Tools;

use HMS\Entities\{User,Role,Banking\Account};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HttpTestCase;

final class MemberSearchHttpTest extends HttpTestCase
{
    public static function authorities(): array{return [['tools.search.users',false,false],['search.users',true,false],['search.users',true,true]];}
    #[DataProvider('authorities')]
    public function testHTTP07CurrentMemberRestrictionAndSensitiveFieldVisibility(string $permission,bool $both,bool $private): void
    {
        $this->allow(array_filter([$permission,$private?'profile.view.all':null]));
        $current=$this->member('searchcurrent');$former=$this->member('searchformer');
        $role=\Tests\Support\Fixtures::role(Role::MEMBER_CURRENT);$this->persist($role);
        $user=$this->em->find(User::class,$current);$user->getRoles()->add($role);$user->getProfile()->setAddress1('Synthetic street')->setAddressPostcode('TE5 1ST')->setDiscordUsername('synthetic');$this->persist($user);
        $r=$this->request('GET','/api/search/users',['q'=>'search','currentOnly'=>false]);$r->assertOk();$rows=$r->json('data');
        self::assertEqualsCanonicalizing($both?[$current,$former]:[$current],array_column($rows,'id'));
        foreach($rows as $row){self::assertSame(['id','fullname','username','email','accountId','paymentRef','discordUsername'],array_values(array_filter(array_keys($row),fn($k)=>!in_array($k,['address1','addressPostcode']))));self::assertSame($private,array_key_exists('address1',$row));self::assertArrayNotHasKey('password',$row);}
        if($private){$row=array_values(array_filter($rows,fn($x)=>$x['id']===$current))[0];self::assertSame('Synthetic street',$row['address1']);}
    }
    public function testHTTP07NoPermissionDeniedAndEmptySearchReturnsEmptyArray(): void
    {
        $this->request('GET','/api/search/users',['q'=>'member'])->assertForbidden();
        $this->allow(['tools.search.users']);$this->request('GET','/api/search/users')->assertOk()->assertExactJson([]);
    }
    public function testHTTP07PathQueryOverrideAndWithAccountFilter(): void
    {
        $this->allow(['search.users']);$with=$this->member('withaccount');$without=$this->member('noaccount');
        $account=new Account('SYNTHETIC-REF');$this->persist($account);$user=$this->em->find(User::class,$with);$user->setAccount($account);$this->persist($user);
        $r=$this->request('GET','/api/search/users/noaccount',['q'=>'withaccount','withAccount'=>true]);$r->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$with)->assertJsonPath('data.0.paymentRef','SYNTHETIC-REF');
        $this->request('GET','/api/search/users/noaccount')->assertOk()->assertJsonPath('data.0.id',$without);
    }
}
