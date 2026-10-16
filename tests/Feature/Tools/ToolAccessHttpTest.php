<?php
namespace Tests\Feature\Tools;

use HMS\Entities\Tools\Tool;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HttpTestCase;

final class ToolAccessHttpTest extends HttpTestCase
{
    public static function grants(): array{return [
        ['USER','tools.user.grant',true],['USER',null,false],
        ['INDUCTOR','tools.inductor.grant',true],['INDUCTOR','tools.spike.inductor.grant',true],['INDUCTOR','tools.spike.induct',false],
        ['MAINTAINER','tools.maintainer.grant',true],['MAINTAINER','tools.spike.maintain',false],
    ];}
    #[DataProvider('grants')]
    public function testHTTP06GrantRevokeAuthorityAndActualAudit(string $type,?string $permission,bool $allowed): void
    {
        if($permission)$this->allow([$permission]);$target=$this->member('target');$toolId=$this->routedTool->getId();
        $role='tools.spike.'.strtolower($type);$roleId=(int)$this->scalar('SELECT id FROM roles WHERE name=?',[$role]);
        $grant='/tools/'.$toolId.'/grant';$revoke='/tools/'.$toolId.'/revoke/'.$type.'/users/'.$target;
        $before=$this->rows('SELECT * FROM role_user ORDER BY user_id,role_id');
        $r=$this->request('PATCH',$grant,['grantType'=>$type,'user_id'=>$target],'web');
        if(!$allowed){$r->assertForbidden();$this->request('DELETE',$revoke,[],'web')->assertForbidden();self::assertSame($before,$this->rows('SELECT * FROM role_user ORDER BY user_id,role_id'));self::assertSame(0,(int)DB::table('role_updates')->count());return;}
        $r->assertRedirect();$this->request('PATCH',$grant,['grantType'=>$type,'user_id'=>$target],'web')->assertRedirect();
        self::assertSame(1,(int)$this->scalar('SELECT COUNT(*) FROM role_user WHERE role_id=? AND user_id=?',[$roleId,$target]));
        $a=DB::table('role_updates')->first();self::assertSame($this->user,(int)$a->update_by_user_id);self::assertSame($target,(int)$a->user_id);self::assertSame($roleId,(int)$a->added_role_id);
        if($type==='USER')$this->request('GET','/tools/'.$toolId.'/users/USER',[],'web',false)->assertOk()->assertSee('Synthetic Member');
        $this->request('DELETE',$revoke,[],'web')->assertRedirect();$this->request('DELETE',$revoke,[],'web')->assertRedirect();
        self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM role_user WHERE role_id=? AND user_id=?',[$roleId,$target]));self::assertSame(2,(int)DB::table('role_updates')->count());
    }
    public function testHTTP06InvalidGrantAndMissingTargetDoNotChangeRoles(): void
    {
        $this->allow(['tools.user.grant']);$path='/tools/'.$this->routedTool->getId().'/grant';$before=DB::table('role_user')->count();
        $this->request('PATCH',$path,['grantType'=>'INVALID','user_id'=>999999],'web')->assertUnprocessable()->assertJsonValidationErrors(['grantType','user_id']);
        $this->request('DELETE','/tools/'.$this->routedTool->getId().'/revoke/USER/users/999999',[],'web')->assertNotFound();
        self::assertSame($before,DB::table('role_user')->count());self::assertSame(0,(int)DB::table('role_updates')->count());
    }
    public function testHTTP06GrantListsEnforceReadAuthority(): void
    {
        $this->allow(['tools.spike.maintain']);$path='/tools/'.$this->routedTool->getId().'/users/';
        foreach(['MAINTAINER','INDUCTOR'] as $type)$this->request('GET',$path.$type,[],'web',false)->assertOk()->assertSee('No users with this access level.');
        foreach(['USER','INVALID'] as $type)$this->request('GET',$path.$type,[],'web')->assertForbidden();
    }
    public function testHTTP06FreeTimePersistsSignedDurationWithoutFinancialCharge(): void
    {
        $this->allow(['tools.addFreeTime']);$before=$this->rows('SELECT * FROM transactions ORDER BY id');$path='/tools/'.$this->routedTool->getId().'/free-time';
        $this->request('POST',$path,['user_id'=>$this->user,'hours'=>1,'minutes'=>30],'web')->assertRedirect();
        $row=DB::table('tool_usages')->first();self::assertSame(-5400,(int)$row->duration);self::assertSame('COMPLETE',$row->status);self::assertSame($this->user,(int)$row->user_id);self::assertSame($before,$this->rows('SELECT * FROM transactions ORDER BY id'));
        $this->request('POST',$path,['user_id'=>999999,'hours'=>'invalid','minutes'=>0],'web')->assertUnprocessable()->assertJsonValidationErrors(['user_id','hours']);self::assertSame(1,(int)DB::table('tool_usages')->count());
    }
    public function testHTTP06FreeTimeWithoutAmountShouldReturnValidationError(): void
    {
        $this->allow(['tools.addFreeTime']);$before=$this->rows('SELECT * FROM tool_usages');
        $this->request('POST','/tools/'.$this->routedTool->getId().'/free-time',['user_id'=>$this->user],'web')->assertUnprocessable();
        self::assertSame($before,$this->rows('SELECT * FROM tool_usages'));
    }
}
