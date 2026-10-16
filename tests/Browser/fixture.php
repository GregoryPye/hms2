<?php

require '/app/tests/Browser/bootstrap.php';
use HMS\Entities\{Profile,Role};
use Illuminate\Support\Facades\DB;
use Tests\Support\{DatabaseSandbox,Fixtures};

function emitFixture(string $name, array $data): void {
    $text = json_encode($data, JSON_THROW_ON_ERROR);
    ensure(strlen($text) < 1024 * 1024, 'Oversize browser fixture report');
    echo 'HMS_ARTIFACT:'.$name.':'.base64_encode($text)."\n";
}
try {
    $app = browserApplication();
    $db = database();
    $directory = '/app/storage/framework/browser';
    $mode = $argv[1] ?? '';
    if ($mode === 'seed') {
        (new DatabaseSandbox($app))->recreate();
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        $key = openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        foreach (['private'=>$private, 'public'=>openssl_pkey_get_details($key)['key']] as $kind=>$value) {
            file_put_contents($directory.'/'.$kind.'.pem', $value); chmod($directory.'/'.$kind.'.pem', 0600);
        }
        $em = $app['em'];
        foreach (config('roles.permissions') as $name) DB::table('permissions')->insertOrIgnore(['name'=>$name]);
        foreach (['induction_request_html','rules_html','members_guide_html'] as $name) DB::table('meta')->updateOrInsert(['key'=>$name],['value'=>'/test/'.$name]);
        foreach ([['tools.tool.index','main'],['tools.booking.index','directions'],['logo','svg']] as [$view,$block]) {
            $em->persist(Fixtures::content()->setView($view)->setBlock($block));
        }
        $ids = [];
        foreach (['admin','reader','denied','target'] as $name) {
            $user = Fixtures::user('browser_'.$name)->setLastname(ucfirst($name)); $user->setProfile(new Profile($user));
            $em->persist($user); $em->persist($user->getProfile()); $em->flush(); $ids[$name]=$user->getId();
        }
        $manager = $app->make(\HMS\Tools\ToolManager::class);
        $tool = $manager->create('browser','Synthetic browser tool',false,100,30,120);
        $tool->setStatus('FREE'); $tool->setUnRestricted();
        $hidden = $manager->create('hidden','Synthetic hidden tool',false,0,30,120);
        $hidden->setHidden(true); $em->flush();
        $ids['tool']=$tool->getId(); $ids['hidden']=$hidden->getId();
        foreach (['admin'=>['tools.view','tools.book','tools.edit','tools.maintainer.grant','tools.search.users','tools.addFreeTime','profile.view.limited'],
                  'reader'=>['tools.view','tools.book']] as $who=>$permissions) {
            foreach ($permissions as $permission) {
                $role = Fixtures::role('test.browser.'. $who.'.'.str_replace('.','_',$permission), [Fixtures::permission($permission)]);
                // Permission definitions already exist; reference the actual managed entity.
                $role->getPermissions()->clear();
                $role->addPermission($em->getRepository(\LaravelDoctrine\ACL\Permissions\Permission::class)->findOneBy(['name'=>$permission]));
                $em->persist($role); $em->flush();
                DB::table('role_user')->insert(['role_id'=>$role->getId(),'user_id'=>$ids[$who]]);
            }
        }
        $current = Fixtures::role(Role::MEMBER_CURRENT); $em->persist($current); $em->flush();
        DB::table('role_user')->insert(['role_id'=>$current->getId(),'user_id'=>$ids['target']]);
        $start = \Carbon\Carbon::tomorrow('Europe/London')->setTime(13,0);
        $ids['date']=$start->toDateString(); $ids['start']=$start->toIso8601String(); $ids['end']=$start->copy()->addMinutes(30)->toIso8601String();
        file_put_contents($directory.'/fixture.json', json_encode($ids, JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/events.jsonl', ''); emitFixture('fixture.json', $ids);
    } elseif ($mode === 'reset') {
        (new DatabaseSandbox($app))->validate();
        $ids=json_decode(file_get_contents($directory.'/fixture.json'),true,512,JSON_THROW_ON_ERROR);
        foreach (['bookings','role_updates','tool_usages'] as $table) DB::table($table)->delete();
        DB::table('role_user')->where('user_id',$ids['target'])->whereIn('role_id',DB::table('roles')->select('id')->where('name','like','tools.%'))->delete();
        foreach (glob('/app/storage/framework/sessions/*') as $file) { ensure(is_file($file)&&!is_link($file),'Unexpected session file');unlink($file); }
        file_put_contents($directory.'/events.jsonl', '');
        ensure(DB::table('bookings')->count()===0 && DB::table('tool_usages')->count()===0 && DB::table('role_updates')->count()===0, 'Browser reset incomplete');
        emitFixture('fixture.json',['mode'=>'reset','verified'=>true]);
    } elseif ($mode === 'verify') {
        (new DatabaseSandbox($app))->validate();
        $scenario=$argv[2]??''; $ids=json_decode(file_get_contents($directory.'/fixture.json'),true,512,JSON_THROW_ON_ERROR);
        $state=['bookings'=>DB::table('bookings')->count(), 'usages'=>DB::table('tool_usages')->count(),
            'transactions'=>DB::table('transactions')->count(), 'audit'=>DB::table('role_updates')->count(),
            'audit_rows'=>DB::table('role_updates')->get(['user_id','added_role_id','removed_role_id','update_by_user_id'])->toArray(),
            'balances'=>DB::table('profile')->orderBy('user_id')->pluck('balance')->map(fn($value)=>(int)$value)->all(),
            'maintainer_role'=>(int)DB::table('roles')->where('name','tools.browser.maintainer')->value('id'),
            'target_maintainer'=>DB::table('role_user')->join('roles','roles.id','=','role_user.role_id')->where('user_id',$ids['target'])->where('roles.name','tools.browser.maintainer')->count(),
            'events'=>array_map(fn($line)=>json_decode($line,true,512,JSON_THROW_ON_ERROR)['type'],file($directory.'/events.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES))];

        ensure($state['balances']===[0,0,0,0], 'Unexpected financial balance mutation');
        ensure($state['bookings']===0 && $state['transactions']===0, 'Unexpected retained booking/financial write');
        if ($scenario==='grant') {
            ensure($state['target_maintainer']===1 && $state['audit']===1 && $state['usages']===0, 'Grant identity/audit mismatch');
            $audit=$state['audit_rows'][0];
            $role=(int)DB::table('roles')->where('name','tools.browser.maintainer')->value('id');
            ensure((int)$audit->user_id===$ids['target'] && (int)$audit->added_role_id===$role && $audit->removed_role_id===null && (int)$audit->update_by_user_id===$ids['admin'], 'Grant audit actor/target/role mismatch');
        }
        else ensure($state['target_maintainer']===0 && $state['audit']===0 && $state['usages']===0, 'Unexpected access/usage write');
        if ($scenario==='booking') ensure($state['events']===['NewBooking','BookingChanged','BookingChanged','BookingCancelled'], 'Booking lifecycle event sequence mismatch');
        else ensure($state['events']===[], 'Unexpected booking event');
        $state['scenario']=$scenario;$state['verified']=true;emitFixture('state.json',$state);
    } else { throw new RuntimeException('Unknown browser fixture mode'); }
    \Mockery::close();
} catch (\Throwable $error) {
    // Laravel registers a CLI exception handler while bootstrapping. Catch here
    // to guarantee a nonzero status instead of accepting rendered errors as success.
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
}
