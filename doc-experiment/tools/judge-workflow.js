export const meta = {
  name: 'html-api-docs-judges',
  description: 'Judge one round of test-subject trials, one strongest-available judge per task',
  phases: [
    { title: 'Judge', detail: 'one judge per task, executes nothing destructive', model: 'gpt-5.5' },
  ],
}

const parsedArgs = typeof args === 'string' ? JSON.parse(args) : args
const {
  repoRoot,
  round,
  scratch,
  taskIds,
  model = 'gpt-5.5',
  reasoning_effort = 'xhigh',
  service_tier = 'priority',
} = parsedArgs

const SCHEMA = {
  type: 'object',
  properties: {
    trials: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          trial_id: { type: 'string', description: 'e.g. trial-1' },
          adherence: { type: 'integer', minimum: 0, maximum: 100 },
          hallucinated_methods: { type: 'array', items: { type: 'string' } },
          notes: { type: 'string', minLength: 1 },
        },
        required: ['trial_id', 'adherence', 'hallucinated_methods', 'notes'],
      },
    },
    failure_analysis: { type: 'string', minLength: 1 },
    doc_gaps: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          location: { type: 'string', minLength: 1 },
          problem: { type: 'string', minLength: 1 },
          suggestion: { type: 'string', minLength: 1 },
        },
        required: ['location', 'problem', 'suggestion'],
      },
    },
  },
  required: ['trials', 'failure_analysis', 'doc_gaps'],
}

const verdicts = await parallel(taskIds.map(id => () =>
  agent(
    `You are the judge in a documentation-quality experiment. Less capable "test subject" models implemented a PHP function using ONLY two rendered documentation files plus a task description — no source access, no code execution. You score how they used the API and diagnose which documentation gaps caused failures.

Locations:
- Task spec (what subjects saw): ${repoRoot}/doc-experiment/corpus/${id}/task.md
- Canonical reference: ${repoRoot}/doc-experiment/corpus/${id}/reference.php
- Hidden tests + frozen expectations: ${repoRoot}/doc-experiment/corpus/${id}/tests.json
- Trials: ${repoRoot}/doc-experiment/results/${round}/${id}/trial-N/ directories, each containing candidate.php, response.json (subject's explanation + self-reported confidence), execution.json (hidden-test results: per-case pass/fail with expected vs actual, plus any _doing_it_wrong records)
- The exact docs subjects saw: ${scratch}/html-tag-processor.md and ${scratch}/html-processor.md

Score each trial's ADHERENCE 0-100 by this rubric:
- Correct processor choice for the job (max 30)
- No hallucinated or undocumented API usage (max 30) — verify EVERY method the candidate calls exists in the two markdown files (Grep them); _doing_it_wrong records in execution.json also indicate misuse
- Idiomatic use of documented patterns: token walking, bookmarks, breadcrumbs, get_updated_html, serialize_token (max 25)
- Graceful handling of edge cases the docs describe: null/true/'' attribute semantics, decoded vs raw text, incomplete input (max 15)

Adherence judges HOW the API was used; functional correctness is measured separately by execution.json — do not double-count it, but use failing cases to find the misunderstanding.

Then write failure_analysis: for each failed hidden case across trials, identify the specific misconception and the documentation passage (or absence) responsible — name the markdown section or method heading. If all trials passed everything, analyze what the docs did well and any near-misses in the explanations.

Then list doc_gaps: concrete, GENERALIZABLE improvements to the docblocks (location = class/method or section, problem, suggestion). Never suggest embedding this task's solution into the docs; suggest the general fact or example that would have prevented the failure.

You may verify actual API behavior with probes:
  php -r 'require "${repoRoot}/doc-experiment/harness/bootstrap.php"; <probe code>'
Do not modify any files. Deliver via StructuredOutput.`,
    {
      label: `judge:${id}`,
      phase: 'Judge',
      schema: SCHEMA,
      model,
      reasoning_effort,
      service_tier,
    }
  ).then(v => ({ id, verdict: v }))
))

const completed = verdicts.filter(Boolean).filter(v => v.verdict)
log(`${completed.length}/${taskIds.length} judges returned`)
return completed
