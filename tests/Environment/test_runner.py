"""Control-plane invariants; no Docker calls, application bootstrap or network work."""
import copy
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('hms_runner', Path(__file__).with_name('run.py'))
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)


class RunnerChecks(unittest.TestCase):
    def testPublishedMailViewsIncludedWithoutDependencyVendorOrSecrets(self):
        from types import SimpleNamespace
        allowed=['resources/views/vendor/mail/html/themes/hms.css',
                 'resources/views/vendor/mail/html/message.blade.php']
        forbidden=['vendor/private.php','public/vendor/private.js','resources/views/vendor/mail/.env',
                   'resources/views/vendor/mail/key.pem','resources/views/vendor/mail/node_modules/private.js']
        with tempfile.TemporaryDirectory() as temp, patch.object(harness,'ROOT',Path(temp)):
            for name in allowed+forbidden:
                path=Path(temp)/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_text('synthetic fixture')
            with patch.object(harness,'command',return_value=SimpleNamespace(stdout='\0'.join(allowed+forbidden))):
                self.assertEqual(sorted(allowed),harness.product_files())

    def reports(self):
        return [{'action': 'pr-check', 'profile': p, 'baseline': {'commit': 'same', 'product_digest': 'same'}, 'tooling_digest': 'same',
                 'outcome': 'pass', 'requested_cycles': 2, 'completed_cycles': 2,
                 'results': [{'cycle': cycle, 'check': check, 'status': 'pass'}
                             for cycle in (1, 2) for check in sorted(harness.required_checks(p, 'pr-check'))]}
                for p in harness.PROFILES]

    def testRequiredFailureMissingDuplicateStaleAndIncompleteCannotAggregateGreen(self):
        reports = self.reports()
        self.assertEqual('pass', harness.aggregate(reports)['outcome'])
        for mutation in ['failure', 'missing', 'duplicate', 'stale', 'incomplete', 'blocked', 'omitted-check', 'smoke-only']:
            changed = copy.deepcopy(reports)
            if mutation == 'omitted-check': changed[0]['results'].pop()
            if mutation == 'smoke-only': changed[0]['action'] = 'smoke'
            if mutation == 'failure': changed[1]['outcome'] = 'blocked'
            if mutation == 'missing': changed.pop()
            if mutation == 'duplicate': changed.append(changed[0])
            if mutation == 'stale': changed[2]['tooling_digest'] = 'different'
            if mutation == 'incomplete': changed[0]['completed_cycles'] = 1
            if mutation == 'blocked': changed[1]['results'][0]['status'] = 'blocked'
            self.assertEqual('blocked', harness.aggregate(changed)['outcome'], mutation)

    def testPartialBackendSelectionsCannotSatisfyCompleteAggregate(self):
        for selected in ['sql', 'backend', 'backend-unit', 'backend-integration', 'http', 'maintainer-cancel']:
            reports = self.reports()
            reports[0]['suite'] = selected
            reports[0]['results'] = [{'cycle':cycle,'check':check,'status':'pass'} for cycle in (1,2)
                                    for check in harness.required_checks('backend','pr-check',selected)]
            self.assertTrue(harness.profile_complete(reports[0], 'pr-check'), selected)
            self.assertEqual('blocked', harness.aggregate(reports)['outcome'], selected)
        self.assertIn('http-authentication', harness.required_checks('backend', 'pr-check'))
        self.assertNotIn('sql-charging', harness.required_checks('backend', 'pr-check', 'http'))
        self.assertNotIn('backend-unit', harness.required_checks('backend', 'pr-check', 'http'))
        self.assertNotIn('mysql', harness.required_checks('backend', 'pr-check', 'backend-unit'))
        self.assertNotIn('backend-unit', harness.required_checks('backend', 'pr-check', 'backend-integration'))

    def testCoverageUnionsStatementsAndKeepsUncoveredLinesWithoutRepeatInflation(self):
        with tempfile.TemporaryDirectory() as temp:
            paths = [Path(temp) / name for name in ['first.xml', 'second.xml']]
            for index, path in enumerate(paths):
                path.write_text('<coverage><project><file name="/app/app/HMS/Tools/Example.php">'
                                '<line num="10" type="stmt" count="' + str(1-index) + '"/>'
                                '<line num="11" type="stmt" count="' + str(index) + '"/>'
                                '</file><file name="/app/app/Http/Example.php">'
                                '<line num="2" type="stmt" count="0"/></file></project></coverage>')
            result = harness.coverage_summary(paths + paths)
            self.assertEqual({'covered': 2, 'executable': 2, 'percent': 100.0}, result['core'])
            self.assertEqual({'covered': 2, 'executable': 3, 'percent': 66.67}, result['full'])
            self.assertEqual(2, result['source_files'])
            paths[0].write_text('<coverage><file name="/etc/secret"/></coverage>')
            with self.assertRaisesRegex(RuntimeError, 'Unexpected coverage source'):
                harness.coverage_summary(paths)

    def testFrontendBusinessRequiredSeparatelyFromSmoke(self):
        self.assertNotIn('frontend-components', harness.required_checks('frontend', 'smoke'))
        self.assertIn('frontend-components', harness.required_checks('frontend', 'pr-check'))
        reports = self.reports()
        reports[1]['results'] = [r for r in reports[1]['results'] if r['check'] != 'frontend-components']
        self.assertFalse(harness.profile_complete(reports[1], 'pr-check'))

    def testJavascriptCoverageRequiresAllSevenSourcesAndConsistentNonemptyCounts(self):
        import json
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / 'coverage.json'
            metrics = ('statements', 'branches', 'functions', 'lines')
            data = {'/tools/source/' + name: {metric: {'total': 2, 'covered': 1, 'pct': 50}
                    for metric in metrics} for name in harness.FRONTEND_SOURCES}
            data['total'] = {metric: {'total': 14, 'covered': 7, 'pct': 50} for metric in metrics}
            path.write_text(json.dumps(data))
            self.assertEqual(7, harness.javascript_coverage_summary(path)['source_files'])
            for mutation in ('missing', 'unexpected', 'empty', 'inconsistent', 'negative'):
                changed = copy.deepcopy(data)
                if mutation == 'missing': changed.pop('/tools/source/SelectTwo.vue')
                if mutation == 'unexpected': changed['/etc/secret'] = changed['total']
                if mutation == 'empty':
                    for entry in changed.values():
                        for metric in metrics: entry[metric].update(total=0, covered=0)
                if mutation == 'inconsistent': changed['total']['lines']['covered'] = 8
                if mutation == 'negative': changed['total']['lines']['covered'] = -1
                path.write_text(json.dumps(changed))
                with self.assertRaises(RuntimeError, msg=mutation):
                    harness.javascript_coverage_summary(path)

    def testJunitCountsLeavesAndPreservesFailureErrorAndSkip(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / 'junit.xml'
            path.write_text('<testsuites><testsuite tests="4"><testsuite tests="4">'
                            '<testcase name="pass" assertions="3"/>'
                            '<testcase name="contract" assertions="1"><failure>real defect</failure></testcase>'
                            '<testcase name="broken"><error>SQL error</error></testcase>'
                            '<testcase name="missing"><skipped>deferred</skipped></testcase>'
                            '</testsuite></testsuite></testsuites>')
            result = harness.junit_summary(path)
            self.assertEqual((4, 4, 1, 1, 1, 1), tuple(result[k] for k in ['tests', 'assertions', 'passed', 'failures', 'errors', 'skipped']))
            self.assertEqual(['failure', 'error', 'skipped'], [r['kind'] for r in result['nonpassing']])
            path.write_text('<testsuites/>')
            with self.assertRaisesRegex(RuntimeError, 'no test cases'):
                harness.junit_summary(path)

    def testArtifactTraversalAndDuplicatesCannotWriteOutsideReportDirectory(self):
        with tempfile.TemporaryDirectory() as temp:
            destination = Path(temp) / 'reports'
            with self.assertRaises(RuntimeError):
                harness.extract_artifacts('HMS_ARTIFACT:../escape:YWJj', destination, ())
            self.assertFalse((Path(temp) / 'escape').exists())
            for name in ('coverage-final.json', 'coverage-summary.json'):
                _, missing = harness.extract_artifacts('HMS_ARTIFACT:' + name + ':e30=', destination, (name,))
                self.assertEqual([], missing)
                self.assertEqual('{}', (destination / name).read_text())
            with self.assertRaises(RuntimeError):
                harness.extract_artifacts('HMS_ARTIFACT:junit.xml:YWJj\nHMS_ARTIFACT:junit.xml:YWJj', destination, ())

    def testCleanupDoesNotDeleteBorrowedResourcesOrRetargetedImage(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(harness, 'CACHE', Path(temp)):
            harness.write_json(harness.CACHE / 'ownership.json', {'images': [{'profile': 'backend',
                'ref': 'owned:tag', 'id': 'expected'}], 'volumes': [], 'build_cache': []})
            calls = []
            def docker(*args, **kwargs):
                calls.append(args)
                return type('Result', (), {'returncode': 0, 'stdout': '[{"Id":"replaced"}]'})()
            with patch.object(harness, 'docker', docker), self.assertRaises(RuntimeError):
                harness.clean('backend')
            self.assertEqual([('image', 'inspect', 'owned:tag')], calls)

    def testBrowserToolingCannotReplaceActualJourneysAndStateChecks(self):
        checks = harness.required_checks('browser', 'pr-check')
        self.assertNotIn('journey-booking-mobile', harness.required_checks('browser', 'smoke'))
        for name in ('journey-booking-mobile', 'application-journey-grant-desktop-state',
                     'journey-maintainer-mobile','application-journey-maintainer-desktop-state',
                     'application-reject-credential', 'application-server-ready','application-reject-unverified-state'):
            self.assertIn(name, checks)
            reports = self.reports()
            reports[2]['results'] = [r for r in reports[2]['results'] if r['check'] != name]
            self.assertEqual('blocked', harness.aggregate(reports)['outcome'], name)

    def testBrowserPreparationRejectsMissingOrStaleApplicationDependency(self):
        import json
        browser = {'profile': 'browser', 'inputs': {'snapshot': 'current'}, 'images': {}}
        browser['volume'] = 'hms-tests-browser-' + harness.digest(browser['inputs'])[:16]
        app = {'profile': 'backend', 'inputs': {'snapshot': 'current'}, 'images': {}}
        app['volume'] = 'hms-tests-backend-' + harness.digest(app['inputs'])[:16]
        def docker(*args, **kwargs):
            profile = 'browser' if args[2] == browser['volume'] else 'backend'
            return type('Result', (), {'returncode':0, 'stdout':json.dumps([{'Labels':{'hms2.harness.profile':profile}}])})()
        with patch.object(harness, 'docker', docker), patch.object(harness, 'inputs', return_value=app['inputs']):
            self.assertFalse(harness.manifest_ready(browser))
            browser['application'] = copy.deepcopy(app)
            self.assertTrue(harness.manifest_ready(browser))
            browser['application']['inputs'] = {'snapshot':'stale'}
            self.assertFalse(harness.manifest_ready(browser))

    def testBrowserJsonMustMatchRequestedIdentityAndJunitOutcome(self):
        import json
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp)
            (path / 'junit.xml').write_text('<testsuite><testcase name="booking"/></testsuite>')
            good = {'scenario':'booking','viewport':'mobile','status':'passed'}
            (path / 'journeys.json').write_text(json.dumps(good))
            self.assertEqual(1,harness.browser_journey_summary(path,'booking','mobile')['passed'])
            for key, value in [('scenario','visibility'),('viewport','desktop'),('status','failed')]:
                changed = dict(good);changed[key] = value
                (path / 'journeys.json').write_text(json.dumps(changed))
                with self.assertRaises(RuntimeError):harness.browser_journey_summary(path,'booking','mobile')

    def testZeroExitCannotReplaceVerifiedPersistedJourneyState(self):
        import json
        with tempfile.TemporaryDirectory() as temp:
            path=Path(temp)/'state.json';fixture={'admin':1,'target':4}
            good={'scenario':'grant','verified':True,'bookings':0,'transactions':0,'usages':0,'balances':[0,0,0,0],
                  'events':[],'target_maintainer':1,'audit':1,'maintainer_role':9,
                  'audit_rows':[{'user_id':4,'update_by_user_id':1,'added_role_id':9,'removed_role_id':None}]}
            path.write_text(json.dumps(good));self.assertEqual(1,harness.browser_state_summary(path,'grant',fixture)['audit'])
            for mutation in ('unverified','wrong-journey','booking','money','actor','target','role','missing-audit'):
                changed=copy.deepcopy(good)
                if mutation=='unverified':changed.pop('verified')
                if mutation=='wrong-journey':changed['scenario']='booking'
                if mutation=='booking':changed['bookings']=1
                if mutation=='money':changed['balances'][0]=1
                if mutation=='actor':changed['audit_rows'][0]['update_by_user_id']=4
                if mutation=='target':changed['audit_rows'][0]['user_id']=1
                if mutation=='role':changed['audit_rows'][0]['added_role_id']=99
                if mutation=='missing-audit':changed['audit_rows']=[]
                path.write_text(json.dumps(changed))
                with self.assertRaises(RuntimeError,msg=mutation):harness.browser_state_summary(path,'grant',fixture)

    def testMaintainerJourneyRequiresExactNotificationAndEventEvidence(self):
        import json
        with tempfile.TemporaryDirectory() as temp:
            path=Path(temp)/'state.json'
            good={'scenario':'maintainer','verified':True,'bookings':0,'transactions':0,'usages':0,'balances':[0,0,0,0],
                  'events':['NewBooking','NewBooking','BookingCancelled','BookingCancelled'],
                  'mail':[{'recipient_id':2,'tool':'browser'}],'target_maintainer':0,'audit':0,'audit_rows':[]}
            path.write_text(json.dumps(good))
            harness.browser_state_summary(path,'maintainer',{'reader':2})
            for mutation in ('missing','wrong-recipient','duplicate','wrong-tool','missing-cancel','owner-notified'):
                changed=copy.deepcopy(good)
                if mutation=='missing':changed.pop('mail')
                if mutation=='wrong-recipient':changed['mail'][0]['recipient_id']=1
                if mutation=='duplicate':changed['mail']*=2
                if mutation=='wrong-tool':changed['mail'][0]['tool']='other'
                if mutation=='missing-cancel':changed['events'].pop()
                if mutation=='owner-notified':changed['mail'].append({'recipient_id':1,'tool':'browser'})
                path.write_text(json.dumps(changed))
                with self.assertRaises(RuntimeError,msg=mutation):harness.browser_state_summary(path,'maintainer',{'reader':2})

    def testBuildInputReleaseRejectsRetargetingWithoutRemovingRuntime(self):
        import json
        with tempfile.TemporaryDirectory() as temp, patch.object(harness, 'CACHE', Path(temp)):
            state = {'images':[{'profile':'backend','ref':harness.IMAGES['composer'],'id':'owned'},
                               {'profile':'backend','ref':'runtime:keep','id':'runtime'}],
                     'volumes':[{'profile':'backend','name':'source:keep'}], 'build_cache':[]}
            harness.write_json(harness.CACHE / 'ownership.json',state)
            with patch.object(harness,'docker',return_value=type('Result',(),{'returncode':0,'stdout':json.dumps([{'Id':'replaced'}])})()) as docker:
                with self.assertRaisesRegex(RuntimeError,'reference changed'):
                    harness.release_backend_build_inputs()
                docker.assert_called_once_with('image','inspect',harness.IMAGES['composer'],check=False)
            self.assertEqual(state,harness.ownership())

    def testBuildCacheReleaseKeepsRuntimeAndBorrowedResources(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(harness, 'CACHE', Path(temp)):
            state = {'images':[{'profile':'backend','ref':'keep-runtime','id':'keep'}],
                     'volumes':[{'profile':'backend','name':'keep-source'}],
                     'build_cache':[{'profile':'backend','id':'owned-build'}, {'profile':'frontend','id':'other-build'}]}
            harness.write_json(harness.CACHE / 'ownership.json',state)
            with patch.object(harness, 'docker') as docker, patch.object(harness, 'build_cache_ids', return_value={'other-build'}):
                harness.clean_build_cache('backend')
                docker.assert_called_once_with('buildx','prune','--all','--force','--filter','id=owned-build')
            kept = harness.ownership()
            self.assertEqual(state['images'],kept['images']);self.assertEqual(state['volumes'],kept['volumes'])
            self.assertEqual([{'profile':'frontend','id':'other-build'}],kept['build_cache'])

    def testSourceMismatchStopsBeforeAnyDockerCall(self):
        with tempfile.TemporaryDirectory() as temp:
            manifest = {'profile': 'frontend', 'inputs': {'baseline': {}, 'tooling_digest': 'old', 'stale': True}}
            run = harness.ProfileRun(manifest, 2, Path(temp), True)
            with patch.object(harness, 'inputs', return_value={'current': True}), patch.object(harness, 'docker') as docker:
                with self.assertRaisesRegex(RuntimeError, 'changed since preparation'):
                    run.run()
                docker.assert_not_called()


if __name__ == '__main__':
    unittest.main()
