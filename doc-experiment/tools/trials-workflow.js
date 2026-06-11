export const meta = {
  name: 'html-api-docs-trials',
  description: 'Run documentation-only test-subject trials for one evaluation round',
  phases: [
    { title: 'Trials', detail: 'one agent per task-trial, docs-only' },
  ],
}

const parsedArgs = typeof args === 'string' ? JSON.parse(args) : args
const { scratch, taskIds, trialsPerTask, model } = parsedArgs

const SCHEMA = {
  type: 'object',
  properties: {
    code: {
      type: 'string',
      description: 'Complete PHP file contents defining exactly the requested function, starting with <?php',
    },
    explanation: {
      type: 'string',
      description: 'One short paragraph: approach and which documented APIs were used',
    },
    confidence: {
      type: 'integer',
      minimum: 0,
      maximum: 100,
      description: 'Confidence the implementation passes a strict behavioral test suite',
    },
  },
  required: ['code', 'explanation', 'confidence'],
}

const pairs = []
for (const id of taskIds) {
  for (let t = 1; t <= trialsPerTask; t++) {
    pairs.push({ id, trial: t })
  }
}

const results = await parallel(pairs.map(p => () =>
  agent(
    `You are a test subject in a documentation-quality experiment, implementing a PHP function for WordPress using the HTML API.

Read your task description from: ${scratch}/tasks/${p.id}.md

Your ONLY sources of information about the HTML API are these two documentation files:
- ${scratch}/html-tag-processor.md
- ${scratch}/html-processor.md

Strict rules: you may use ONLY the Read and Grep tools, and ONLY on the three files listed above. Do not read any other file or directory. Do not run any code or commands. Do not rely on memory of WordPress source code — if the documentation contradicts your memory, trust the documentation. Methods not documented in those two documentation files do not exist.

Deliver via StructuredOutput: code (a complete PHP file defining exactly the requested function), explanation (one short paragraph: your approach and which documented APIs you used), confidence (integer 0-100: how confident you are the implementation passes a strict behavioral test suite).`,
    { label: `${p.id}/trial-${p.trial}`, phase: 'Trials', schema: SCHEMA, model }
  ).then(r => ({ id: p.id, trial: p.trial, ok: !!r, ...(r ?? {}) }))
))

const completed = results.filter(Boolean)
log(`${completed.length}/${pairs.length} trials returned`)
return completed