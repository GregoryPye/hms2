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

    def testSqlOnlyCannotSatisfyCompleteAggregate(self):
        reports = self.reports()
        reports[0]['suite'] = 'sql'
        reports[0]['results'] = [r for r in reports[0]['results'] if not r['check'].startswith('foundation-')]
        self.assertTrue(harness.profile_complete(reports[0], 'pr-check'))
        self.assertEqual('blocked', harness.aggregate(reports)['outcome'])

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
