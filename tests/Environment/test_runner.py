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
