const GITHUB_API_VERSION = "2022-11-28";

async function dispatchWorkflow(env) {
  const url = `https://api.github.com/repos/${env.GITHUB_OWNER}/${env.GITHUB_REPO}/actions/workflows/${env.GITHUB_WORKFLOW}/dispatches`;

  const response = await fetch(url, {
    method: "POST",
    headers: {
      Accept: "application/vnd.github+json",
      Authorization: `Bearer ${env.GITHUB_TOKEN}`,
      "X-GitHub-Api-Version": GITHUB_API_VERSION,
      "User-Agent": "tv-keyword-cloudflare-scheduler"
    },
    body: JSON.stringify({
      ref: env.GITHUB_REF || "main"
    })
  });

  if (!response.ok) {
    const body = await response.text();
    throw new Error(`GitHub Actionsの起動に失敗しました: ${response.status} ${body}`);
  }
}

export default {
  async scheduled(event, env, ctx) {
    ctx.waitUntil(dispatchWorkflow(env));
  },

  async fetch(request, env) {
    const url = new URL(request.url);

    if (url.pathname !== "/health") {
      return new Response("Not Found", { status: 404 });
    }

    return Response.json({
      ok: true,
      repository: `${env.GITHUB_OWNER}/${env.GITHUB_REPO}`,
      workflow: env.GITHUB_WORKFLOW,
      ref: env.GITHUB_REF || "main"
    });
  }
};
