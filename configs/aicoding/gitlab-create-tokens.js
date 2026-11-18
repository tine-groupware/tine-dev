// Creates the two gitlab tokens of the AI coding setup (README "Settings and tokens") as fine-grained personal
// access tokens of the logged in user, limited to the projects below.
//
// Usage: log in to gitlab in the browser, open the developer console on any gitlab page, paste this file, Enter.
// It prints the lines for the .env of tine-dev. The tokens are shown only once - copy them right away.
// Needs gitlab >= 18.7 (GraphQL mutation personalAccessTokenCreate, status "experiment" there).
// Check / revoke the tokens in gitlab: avatar -> Preferences -> Access tokens.
(async () => {
    const projects = ['tine20/tine20', 'tine20/docker']; // the projects you work on with the client
    const days = 180;
    const tokens = {
        // gitlab-mcp: MRs, review threads and notes, pipelines, job logs and artifacts, file contents
        GITLAB_ACCESS_TOKEN: ['read_project', 'read_code', 'read_repository',
            'read_merge_request', 'create_merge_request', 'update_merge_request',
            'read_work_item', 'create_work_item',
            'read_pipeline', 'read_job', 'read_job_artifact'],
        // devctl tools fetch and push (git over https)
        GITLAB_PUSH_TOKEN: ['read_project', 'read_code', 'read_repository', 'push_code', 'read_merge_request'],
    };

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!csrf) throw new Error('run this on a gitlab page while logged in (no csrf token found)');
    const gql = async (query, variables = {}) => {
        const res = await fetch('/api/graphql', {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify({query, variables}),
        });
        const json = await res.json();
        if (json.errors?.length) throw new Error(JSON.stringify(json.errors));
        return json.data;
    };

    const ids = [];
    for (const fullPath of projects) {
        const {project} = await gql('query($p: ID!) { project(fullPath: $p) { id } }', {p: fullPath});
        if (!project) throw new Error('project not found or no access: ' + fullPath);
        ids.push(project.id);
    }

    const expiresAt = new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);
    const env = [];
    for (const [name, permissions] of Object.entries(tokens)) {
        const {personalAccessTokenCreate: r} = await gql(`mutation($input: PersonalAccessTokenCreateInput!) {
            personalAccessTokenCreate(input: $input) { token errors } }`, {input: {
            name: `tine-dev ${name} ${new Date().toISOString().slice(0, 10)}`,
            description: 'tine-dev AI coding setup (configs/aicoding/gitlab-create-tokens.js)',
            expiresAt,
            granularScopes: [{access: 'SELECTED_MEMBERSHIPS', resourceIds: ids, permissions}],
        }});
        if (r.errors?.length || !r.token) throw new Error(name + ': ' + JSON.stringify(r.errors));
        env.push(`${name}=${r.token}`);
    }
    console.log(`# tine-dev .env - tokens valid until ${expiresAt}, projects: ${projects.join(', ')}\n` + env.join('\n'));
})().catch((e) => console.error('creating the tokens failed:', e.message));
