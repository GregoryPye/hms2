<?php
namespace Tests\Feature\Tools;

use HMS\Entities\Tools\Tool;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HttpTestCase;

final class ToolPagesHttpTest extends HttpTestCase
{
    private function details(): array{return ['toolName'=>'httpfixture','displayName'=>'Synthetic HTTP tool','cost'=>125,'bookingLength'=>30,'lengthMax'=>120,'bookingsMax'=>2];}

    public function testHTTP03ActualListHidesHiddenToolsButDirectDetailRemainsVisible(): void
    {
        $this->allow(['tools.view']);$this->routedTool->setHidden(true)->setStatusText('<script>synthetic()</script>');$this->persist($this->routedTool);
        $this->request('GET','/tools',[],'web',false)->assertOk()->assertDontSee('Synthetic lifecycle');
        $this->request('GET','/tools/'.$this->routedTool->getId(),[],'web',false)->assertOk()->assertSee('spike')->assertDontSee('Edit</a>',false);
        $this->request('GET','/tools/'.$this->tool.'/bookings',[],'web',false)->assertOk()->assertSee('tool-calendar',false)->assertSee('user-can-book',false);
    }
    public function testHTTP03EditorSeesHiddenToolAndEscapedDisabledReason(): void
    {
        $this->allow(['tools.view','tools.edit']);$this->routedTool->setHidden(true)->setStatusText('<script>synthetic()</script>');$this->persist($this->routedTool);
        $this->request('GET','/tools',[],'web',false)->assertOk()->assertSee('Synthetic lifecycle')->assertSee('&lt;script&gt;synthetic()&lt;/script&gt;',false)->assertDontSee('<script>synthetic()</script>',false);
        $this->request('GET','/tools/'.$this->routedTool->getId().'/edit',[],'web',false)->assertOk()->assertSee('Synthetic lifecycle');
    }
    public static function unauthorized(): array{return [['GET','/tools/create'],['POST','/tools'],['GET','/tools/1/edit'],['PATCH','/tools/1'],['DELETE','/tools/1'],['GET','/tools/1/usage'],['POST','/tools/1/free-time']];}
    #[DataProvider('unauthorized')]
    public function testHTTP02WebActionPermissionsDenyWithNoPersistence(string $method,string $path): void
    {
        $before=$this->snapshot();$r=$this->request($method,$path,$this->details(),'web');$r->assertForbidden();self::assertSame($before,$this->snapshot());
    }
    public function testHTTP04RealCreateUpdateDuplicateValidationAndRemoval(): void
    {
        $this->allow(['tools.create','tools.edit','tools.destroy','tools.view']);
        $this->request('GET','/tools/create',[],'web',false)->assertOk()->assertSee('Add new tool');
        $data=$this->details();$r=$this->request('POST','/tools',$data,'web');$r->assertRedirect();
        $id=(int)DB::table('tools')->where('name','httpfixture')->value('id');self::assertGreaterThan(0,$id);$r->assertRedirect('http://hms.test/tools/'.$id);
        self::assertSame(3,(int)DB::table('roles')->where('name','like','tools.httpfixture.%')->count());
        $count=DB::table('tools')->count();$this->request('POST','/tools',$data,'web')->assertUnprocessable()->assertJsonValidationErrors('toolName');self::assertSame($count,DB::table('tools')->count());
        $data['displayName']='Synthetic updated';$data['hidden']='on';$data['restricted']='on';$this->request('PATCH','/tools/'.$id,$data,'web')->assertRedirect('http://hms.test/tools/'.$id);
        self::assertSame('Synthetic updated',DB::table('tools')->where('id',$id)->value('display_name'));
        $this->request('DELETE','/tools/'.$id,[],'web')->assertRedirect('http://hms.test/tools');self::assertFalse(DB::table('tools')->where('id',$id)->exists());
    }
    public static function invalidDetails(): array{return [['toolName',str_repeat('x',21)],['cost',-1],['lengthMax','text'],['bookingsMax',0]];}
    #[DataProvider('invalidDetails')]
    public function testHTTP04ValidationIs422AndCreatesNoTool(string $field,$value): void
    {
        $this->allow(['tools.create']);$data=$this->details();$data[$field]=$value;$before=$this->rows('SELECT * FROM tools ORDER BY id');
        $this->request('POST','/tools',$data,'web')->assertUnprocessable()->assertJsonValidationErrors($field);self::assertSame($before,$this->rows('SELECT * FROM tools ORDER BY id'));
    }
    public function testHTTP04MissingBoundToolIs404(): void
    {
        $this->allow(['tools.view','tools.edit','tools.destroy']);
        foreach(['GET','PATCH','DELETE'] as $method)$this->request($method,'/tools/999999',$this->details(),'web')->assertNotFound();
    }
    public function testHTTP03UsageQueryAcceptsMaintainerAndValidatesDates(): void
    {
        $this->allow(['tools.spike.maintain']);$path='/tools/'.$this->routedTool->getId().'/usage';
        $this->request('GET',$path,['startDate'=>'bad'],'web')->assertUnprocessable()->assertJsonValidationErrors(['startDate','endDate']);
        $this->request('GET',$path,[],'web',false)->assertOk()->assertSee('Synthetic lifecycle');
        $this->request('GET',$path,['startDate'=>'2025-02-15','endDate'=>'2025-02-15'],'web',false)->assertOk();
    }
}
