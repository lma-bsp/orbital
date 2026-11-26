"""Run with: python3 -m unittest discover -s agent-worker/tests"""

import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import heartbeat  # noqa: E402


class EnvKeysTest(unittest.TestCase):
    def test_reports_booleans_for_every_known_provider(self):
        keys = heartbeat.env_keys({"ANTHROPIC_API_KEY": "sk-ant-secret"})

        self.assertEqual(set(keys), set(heartbeat.PROVIDER_ENV))
        self.assertIs(keys["anthropic"], True)
        self.assertIs(keys["openai"], False)
        self.assertTrue(all(isinstance(v, bool) for v in keys.values()))

    def test_never_carries_key_values(self):
        body = repr(heartbeat.payload({"ANTHROPIC_API_KEY": "sk-ant-secret", "HOSTNAME": "w1"}))

        self.assertNotIn("sk-ant-secret", body)

    def test_blank_values_count_as_unset(self):
        self.assertIs(heartbeat.env_keys({"OPENAI_API_KEY": "  "})["openai"], False)

    def test_either_elevenlabs_spelling_counts(self):
        self.assertIs(heartbeat.env_keys({"ELEVEN_API_KEY": "x"})["elevenlabs"], True)
        self.assertIs(heartbeat.env_keys({"ELEVENLABS_API_KEY": "x"})["elevenlabs"], True)

    def test_worker_id_is_sanitised_for_laravel(self):
        self.assertEqual(heartbeat.worker_id({"HOSTNAME": "pod/agent worker"}), "pod-agent-worker")
        self.assertLessEqual(len(heartbeat.worker_id({"HOSTNAME": "a" * 300})), 128)


if __name__ == "__main__":
    unittest.main()
