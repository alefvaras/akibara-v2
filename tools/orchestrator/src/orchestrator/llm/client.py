"""
Anthropic API client wrapper — handles retries, cost tracking, rate limits.
"""
from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from typing import Any

from anthropic import Anthropic, APIError, APITimeoutError, RateLimitError

from orchestrator.config import get_settings

logger = logging.getLogger(__name__)


# Anthropic pricing per million tokens (claude-opus-4-5 as of 2026).
# Update when pricing changes.
PRICING = {
    "claude-opus-4-5": {"input": 15.00, "output": 75.00, "cache_write": 18.75, "cache_read": 1.50},
    "claude-sonnet-4-5": {"input": 3.00, "output": 15.00, "cache_write": 3.75, "cache_read": 0.30},
    "claude-haiku-4-5": {"input": 1.00, "output": 5.00, "cache_write": 1.25, "cache_read": 0.10},
}


@dataclass
class LLMResponse:
    """Response from LLM call with metadata."""

    text: str
    model: str
    input_tokens: int
    output_tokens: int
    cache_read_tokens: int = 0
    cache_write_tokens: int = 0
    cost_usd: float = 0.0
    latency_ms: float = 0.0
    stop_reason: str | None = None


class AnthropicClient:
    """Wrapper para anthropic SDK con retry + cost tracking + rate limit awareness."""

    def __init__(
        self,
        api_key: str | None = None,
        model: str | None = None,
        max_tokens: int = 8192,
        temperature: float = 0.7,
    ) -> None:
        settings = get_settings()
        self.client = Anthropic(api_key=api_key or settings.anthropic_api_key)
        self.model = model or settings.anthropic_model
        self.max_tokens = max_tokens
        self.temperature = temperature

    def call(
        self,
        prompt: str,
        system: str | None = None,
        model: str | None = None,
        max_tokens: int | None = None,
        temperature: float | None = None,
        max_retries: int = 3,
    ) -> LLMResponse:
        """
        Single LLM call with retry on transient errors.

        Raises APIError if all retries exhausted.
        """
        actual_model = model or self.model
        actual_max_tokens = max_tokens or self.max_tokens
        actual_temp = temperature if temperature is not None else self.temperature

        messages = [{"role": "user", "content": prompt}]

        last_exc: Exception | None = None
        for attempt in range(max_retries):
            start = time.time()
            try:
                kwargs: dict[str, Any] = {
                    "model": actual_model,
                    "max_tokens": actual_max_tokens,
                    "temperature": actual_temp,
                    "messages": messages,
                }
                if system:
                    kwargs["system"] = system

                response = self.client.messages.create(**kwargs)

                latency_ms = (time.time() - start) * 1000

                # Extract text
                text = ""
                for block in response.content:
                    if hasattr(block, "text"):
                        text += block.text

                input_t = response.usage.input_tokens
                output_t = response.usage.output_tokens
                cache_read = getattr(response.usage, "cache_read_input_tokens", 0) or 0
                cache_write = getattr(response.usage, "cache_creation_input_tokens", 0) or 0

                cost = self._calculate_cost(actual_model, input_t, output_t, cache_read, cache_write)

                logger.info(
                    "llm_call_success",
                    extra={
                        "model": actual_model,
                        "input_tokens": input_t,
                        "output_tokens": output_t,
                        "cost_usd": round(cost, 6),
                        "latency_ms": round(latency_ms, 1),
                    },
                )

                return LLMResponse(
                    text=text,
                    model=actual_model,
                    input_tokens=input_t,
                    output_tokens=output_t,
                    cache_read_tokens=cache_read,
                    cache_write_tokens=cache_write,
                    cost_usd=cost,
                    latency_ms=latency_ms,
                    stop_reason=response.stop_reason,
                )

            except RateLimitError as e:
                last_exc = e
                wait = (2**attempt) * 5  # exponential backoff: 5s, 10s, 20s
                logger.warning(
                    "llm_rate_limited",
                    extra={"attempt": attempt + 1, "wait_seconds": wait},
                )
                time.sleep(wait)
            except (APITimeoutError, APIError) as e:
                last_exc = e
                wait = 2**attempt
                logger.warning(
                    "llm_transient_error",
                    extra={"attempt": attempt + 1, "error": str(e), "wait_seconds": wait},
                )
                time.sleep(wait)

        # Exhausted retries
        logger.error("llm_call_failed", extra={"error": str(last_exc)})
        raise last_exc or RuntimeError("LLM call failed after retries")

    @staticmethod
    def _calculate_cost(
        model: str,
        input_tokens: int,
        output_tokens: int,
        cache_read: int = 0,
        cache_write: int = 0,
    ) -> float:
        """Cost in USD per Anthropic pricing."""
        pricing = PRICING.get(model)
        if not pricing:
            logger.warning("unknown_model_pricing", extra={"model": model})
            return 0.0

        return (
            (input_tokens - cache_read - cache_write) * pricing["input"]
            + output_tokens * pricing["output"]
            + cache_write * pricing["cache_write"]
            + cache_read * pricing["cache_read"]
        ) / 1_000_000
