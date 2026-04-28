"""
Configuration — pydantic-settings reads from environment + .env file.

Secrets NEVER hardcoded. All sensitive values via env vars.
"""
from pathlib import Path

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Orchestrator configuration."""

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        env_prefix="AKIBARA_ORCH_",
        case_sensitive=False,
        extra="ignore",
    )

    # Core paths
    repo_root: Path = Field(
        default=Path.cwd().parents[1] if Path.cwd().name == "orchestrator" else Path.cwd(),
        description="Akibara repo root (where .git lives)",
    )
    data_dir: Path = Field(
        default=Path("./data"),
        description="Orchestrator data directory (DB, logs, queue)",
    )
    db_url: str = Field(
        default="sqlite+aiosqlite:///./data/orchestrator.db",
        description="SQLAlchemy async DB URL",
    )

    # Anthropic API (mandatory)
    anthropic_api_key: str = Field(
        default="",
        description="ANTHROPIC_API_KEY — required for LLM calls",
    )
    anthropic_model: str = Field(
        default="claude-opus-4-5",
        description="Default model for agents",
    )
    anthropic_max_tokens: int = Field(default=8192)
    anthropic_temperature: float = Field(default=0.7)

    # Connectors (optional, agent declares which it needs)
    github_token: str = Field(default="", description="GH_TOKEN para gh CLI")
    discord_webhook_url: str = Field(default="", description="Discord webhook for notifications")
    brevo_api_key: str = Field(default="")
    sentry_dsn: str = Field(default="")

    # Akibara prod connection
    akibara_ssh_alias: str = Field(default="akibara", description="SSH alias en ~/.ssh/config")
    akibara_prod_url: str = Field(default="https://akibara.cl")
    akibara_staging_url: str = Field(default="https://staging.akibara.cl")

    # Dashboard
    dashboard_host: str = Field(default="127.0.0.1")
    dashboard_port: int = Field(default=8765)
    dashboard_secret: str = Field(default="", description="Token para dashboard auth")

    # Scheduler
    scheduler_enabled: bool = Field(default=True)
    seo_audit_cron: str = Field(default="0 6 * * *", description="Daily 06:00 UTC")
    sentry_digest_cron: str = Field(default="0 14 * * *", description="Daily 14:00 UTC")
    deps_check_cron: str = Field(default="0 9 * * 1", description="Mondays 09:00")

    # Budget limits (cost guards)
    max_cost_per_day_usd: float = Field(default=10.0)
    max_tokens_per_minute: int = Field(default=20000)

    # Logging
    log_level: str = Field(default="INFO")
    log_format: str = Field(default="json", description="json | console")

    def ensure_data_dir(self) -> None:
        """Create data dir if missing."""
        self.data_dir.mkdir(parents=True, exist_ok=True)
        (self.data_dir / "queue").mkdir(exist_ok=True)
        (self.data_dir / "inbox").mkdir(exist_ok=True)
        (self.data_dir / "done").mkdir(exist_ok=True)
        (self.data_dir / "logs").mkdir(exist_ok=True)


# Singleton instance — lazy loaded
_settings: Settings | None = None


def get_settings() -> Settings:
    """Return singleton settings instance."""
    global _settings
    if _settings is None:
        _settings = Settings()
        _settings.ensure_data_dir()
    return _settings
