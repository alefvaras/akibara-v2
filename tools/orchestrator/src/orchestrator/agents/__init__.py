"""Agent registry — maps role to implementation class."""
from orchestrator.agents.base import BaseAgent
from orchestrator.agents.mind_master import MindMasterAgent
from orchestrator.tasks.models import AgentRole

# Registry — extend with new agent classes here
AGENT_REGISTRY: dict[AgentRole, type[BaseAgent]] = {
    AgentRole.MIND_MASTER: MindMasterAgent,
    # AgentRole.THEME: ThemeAgent,    # TODO Phase 2
    # AgentRole.SEO: SEOAgent,        # TODO Phase 2
    # AgentRole.QA: QAAgent,          # TODO Phase 2
}


def get_agent(role: AgentRole, instance_id: str | None = None) -> BaseAgent:
    """Factory — instantiate agent for given role."""
    cls = AGENT_REGISTRY.get(role)
    if not cls:
        raise ValueError(f"No agent registered for role: {role.value}")
    return cls(instance_id=instance_id)


__all__ = ["AGENT_REGISTRY", "BaseAgent", "MindMasterAgent", "get_agent"]
