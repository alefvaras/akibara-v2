"""
Discord webhook — push notifications for agent events.

Used by: scheduler (cron jobs), agents (deploy notifications), dashboard.
"""
from __future__ import annotations

import logging

import httpx

from orchestrator.config import get_settings

logger = logging.getLogger(__name__)


class DiscordWebhook:
    """Simple Discord webhook client."""

    def __init__(self, webhook_url: str | None = None) -> None:
        settings = get_settings()
        self.url = webhook_url or settings.discord_webhook_url
        self.enabled = bool(self.url)

    def send(
        self,
        content: str,
        username: str = "Akibara Orchestrator",
        embeds: list[dict] | None = None,
    ) -> bool:
        """
        Send message to Discord channel.

        Returns True if sent successfully, False if disabled or failed.
        """
        if not self.enabled:
            logger.debug("discord_disabled", extra={"content_preview": content[:80]})
            return False

        payload = {
            "content": content,
            "username": username,
        }
        if embeds:
            payload["embeds"] = embeds

        try:
            with httpx.Client(timeout=10.0) as client:
                response = client.post(self.url, json=payload)
                response.raise_for_status()
                return True
        except Exception:
            logger.exception("discord_send_failed")
            return False

    def deploy_notification(self, sprint: str, commit: str, status: str) -> bool:
        """Standardized deploy notification embed."""
        color = 0x00C853 if status == "success" else 0xFF3B3B
        return self.send(
            content="",
            embeds=[
                {
                    "title": f"🚀 Deploy {sprint} — {status.upper()}",
                    "description": f"Commit: `{commit}`",
                    "color": color,
                    "footer": {"text": "Akibara Orchestrator"},
                }
            ],
        )

    def alert(self, severity: str, title: str, message: str) -> bool:
        """Sentry alert / P0 incident notification."""
        emoji_map = {"P0": "🔴", "P1": "🟡", "P2": "🟢"}
        return self.send(
            content="",
            embeds=[
                {
                    "title": f"{emoji_map.get(severity, '⚠️')} {severity} — {title}",
                    "description": message,
                    "color": 0xFF3B3B if severity == "P0" else 0xF59E0B,
                }
            ],
        )
