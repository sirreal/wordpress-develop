import { readFile } from 'node:fs/promises';

import { COMMENT_MARKER, RELEASE_TAG } from './preview.mjs';

// A stalled connection without a deadline runs until the job timeout kills it,
// which leaves a preview reported as still building and no useful log.
const REQUEST_TIMEOUT_MS = 30_000;

// Snapshots reach 100 MiB and travel through a third-party proxy, so transfers
// need a deadline far longer than an API call yet shorter than any job.
const TRANSFER_TIMEOUT_MS = 300_000;

const API = 'https://api.github.com';
const UPLOADS = 'https://uploads.github.com';
const RETRY_ATTEMPTS = 3;
const RETRY_BASE_MS = 1000;
const BUILD_WORKFLOW = 'docs-playground-preview-build.yml';
const BUILD_JOB = 'Build Code Reference snapshot';

/**
 * The preview publishes from one repository, plus the staging repository that
 * opts in with the DOCS_PREVIEW_STAGING variable. Every trusted mutation asks
 * here first; the workflow jobs carry the same condition in their `if:`.
 *
 * @param {string | undefined} repository
 * @param {string} [stagingValue]
 */
export function assertDeploymentEnabled( repository, stagingValue ) {
	const enabled =
		repository === 'WordPress/wordpress-develop' ||
		( repository === 'sirreal/wordpress-develop' &&
			stagingValue === 'true' );
	if ( ! enabled ) {
		throw new Error( 'The docs preview is disabled in this repository.' );
	}
}

/**
 * The event payload the runner wrote for this job.
 */
export async function readWorkflowEvent() {
	const eventPath = process.env.GITHUB_EVENT_PATH;
	if ( ! eventPath ) {
		throw new Error( 'GITHUB_EVENT_PATH is required.' );
	}
	return JSON.parse( await readFile( eventPath, 'utf8' ) );
}

/**
 * @param {Record<string, unknown>} values
 */
function query( values ) {
	return new URLSearchParams(
		Object.entries( values ).map( ( [ name, value ] ) => [
			name,
			String( value ),
		] )
	).toString();
}

export class GitHubApi {
	/**
	 * @param {string | undefined} repository
	 * @param {string | undefined} token
	 * @param {(...args: any[]) => Promise<any>} [fetchImplementation]
	 */
	constructor( repository, token, fetchImplementation = globalThis.fetch ) {
		this.repository = repository;
		this.token = token;
		this.fetch = fetchImplementation;
	}

	/**
	 * @param {string} url
	 * @param {Record<string, any>} [options]
	 */
	async request( url, options = {} ) {
		const {
			method = 'GET',
			body,
			json,
			contentType,
			allowNotFound = false,
			anonymous = false,
			timeoutMs = REQUEST_TIMEOUT_MS,
		} = options;
		const headers = anonymous
			? undefined
			: {
					Accept: 'application/vnd.github+json',
					Authorization: `Bearer ${ this.token }`,
					'X-GitHub-Api-Version': '2022-11-28',
					...( contentType && { 'Content-Type': contentType } ),
			  };
		const target = url.startsWith( 'https://' ) ? url : `${ API }${ url }`;
		// Only reads repeat. Repeating a mutation could post a second comment
		// or a second upload for an attempt the server had already applied.
		const repeatable = method === 'GET';
		for ( let attempt = 1; ; attempt++ ) {
			const retrying = repeatable && attempt < RETRY_ATTEMPTS;
			let response;
			try {
				response = await this.fetch( target, {
					method,
					headers,
					signal: AbortSignal.timeout( timeoutMs ),
					body: json === undefined ? body : JSON.stringify( json ),
				} );
			} catch ( error ) {
				if ( ! retrying ) {
					throw error;
				}
				await this.backoff( attempt );
				continue;
			}
			if ( allowNotFound && response.status === 404 ) {
				return null;
			}
			if ( ! response.ok ) {
				if (
					retrying &&
					( response.status === 429 || response.status >= 500 )
				) {
					await this.backoff( attempt );
					continue;
				}
				const detail = await response.text();
				throw Object.assign(
					new Error(
						`Request for ${ url } returned HTTP ${
							response.status
						}: ${ detail.slice( 0, 300 ) }`
					),
					{ status: response.status }
				);
			}
			return response.status === 204 ? null : response.json();
		}
	}

	/**
	 * Spreads repeated attempts so that concurrent jobs meeting the same
	 * outage do not return in lockstep.
	 *
	 * @param {number} attempt
	 */
	async backoff( attempt ) {
		const delay =
			RETRY_BASE_MS * 2 ** ( attempt - 1 ) +
			Math.random() * RETRY_BASE_MS;
		await new Promise( ( resolve ) => setTimeout( resolve, delay ) );
	}

	/**
	 * @param {string} path
	 * @param {string | null} [field]
	 */
	async pages( path, field = null ) {
		/** @type {any[]} */
		const values = [];
		for ( let page = 1; ; page++ ) {
			const separator = path.includes( '?' ) ? '&' : '?';
			const response = await this.request(
				`${ path }${ separator }per_page=100&page=${ page }`
			);
			const batch = field ? response[ field ] : response;
			values.push( ...batch );
			if ( batch.length < 100 ) {
				return values;
			}
		}
	}

	/**
	 * Release assets are public and their download URL redirects to a storage
	 * host, so this read deliberately carries no credentials.
	 *
	 * @param {Record<string, any>} asset
	 */
	readAssetJson( asset ) {
		return this.request( asset.browser_download_url, { anonymous: true } );
	}

	/**
	 * @param {number} runId
	 */
	getRun( runId ) {
		return this.request(
			`/repos/${ this.repository }/actions/runs/${ runId }`
		);
	}

	/**
	 * @param {number} pullRequestNumber
	 */
	getPullRequest( pullRequestNumber ) {
		return this.request(
			`/repos/${ this.repository }/pulls/${ pullRequestNumber }`
		);
	}

	/**
	 * The workflow_run event omits the pull request for a fork head, so it is
	 * found from the head repository and branch that the run recorded.
	 *
	 * @param {Record<string, any>} run
	 */
	async findPullRequestForRun( run ) {
		const owner = run.head_repository?.owner?.login;
		if ( ! owner || ! run.head_branch ) {
			return null;
		}
		const pulls = await this.request(
			`/repos/${ this.repository }/pulls?${ query( {
				state: 'open',
				base: 'trunk',
				head: `${ owner }:${ run.head_branch }`,
				per_page: 100,
			} ) }`
		);
		return (
			pulls.find(
				( /** @type {Record<string, any>} */ pullRequest ) =>
					pullRequest.head.sha === run.head_sha &&
					pullRequest.head.repo?.full_name ===
						run.head_repository.full_name
			) || null
		);
	}

	/**
	 * @param {Record<string, unknown>} search
	 */
	async listBuildRuns( search ) {
		const response = await this.request(
			`/repos/${
				this.repository
			}/actions/workflows/${ BUILD_WORKFLOW }/runs?${ query( search ) }`
		);
		return response.workflow_runs;
	}

	/**
	 * The newest build run for a pull request head. Adding an unrelated label
	 * starts a run whose build job skips; that never supersedes a real build.
	 *
	 * @param {Record<string, any>} run
	 */
	async latestPullRequestBuildRun( run ) {
		const runs = await this.listBuildRuns( {
			event: 'pull_request',
			head_sha: run.head_sha,
			per_page: 100,
		} );
		const matching = runs
			.filter(
				( /** @type {Record<string, any>} */ candidate ) =>
					candidate.head_branch === run.head_branch &&
					candidate.head_repository?.full_name ===
						run.head_repository?.full_name
			)
			.sort(
				(
					/** @type {Record<string, any>} */ left,
					/** @type {Record<string, any>} */ right
				) => right.id - left.id
			);
		for ( const candidate of matching ) {
			if ( ! ( await this.isSkippedBuild( candidate ) ) ) {
				return candidate;
			}
		}
		return null;
	}

	async latestTrunkBuildRun() {
		// The API answers newest first, so the first run is the current one.
		const runs = await this.listBuildRuns( {
			event: 'push',
			branch: 'trunk',
			per_page: 1,
		} );
		return runs[ 0 ] || null;
	}

	/**
	 * @param {Record<string, any>} run
	 */
	async isSkippedBuild( run ) {
		const jobs = await this.pages(
			`/repos/${ this.repository }/actions/runs/${ run.id }/attempts/${ run.run_attempt }/jobs`,
			'jobs'
		);
		const build = jobs.find( ( job ) => job.name === BUILD_JOB );
		return ! build || build.conclusion === 'skipped';
	}

	getRelease() {
		return this.request(
			`/repos/${ this.repository }/releases/tags/${ RELEASE_TAG }`,
			{ allowNotFound: true }
		);
	}

	createRelease() {
		return this.request( `/repos/${ this.repository }/releases`, {
			method: 'POST',
			json: {
				tag_name: RELEASE_TAG,
				name: 'Code Reference Playground previews',
				body: 'Automated prerelease for Code Reference Playground snapshots.',
				prerelease: true,
			},
		} );
	}

	/**
	 * @param {number} releaseId
	 */
	listReleaseAssets( releaseId ) {
		return this.pages(
			`/repos/${ this.repository }/releases/${ releaseId }/assets`
		);
	}

	/**
	 * @param {number} releaseId
	 * @param {string} name
	 * @param {Uint8Array} bytes
	 * @param {string} contentType
	 */
	async uploadReleaseAsset( releaseId, name, bytes, contentType ) {
		const upload = () =>
			this.request(
				`${ UPLOADS }/repos/${
					this.repository
				}/releases/${ releaseId }/assets?${ query( { name } ) }`,
				{
					method: 'POST',
					contentType,
					body: bytes,
					timeoutMs: TRANSFER_TIMEOUT_MS,
				}
			);
		try {
			return await upload();
		} catch ( error ) {
			// Re-running the publish workflow for one build attempt meets the
			// asset that the earlier run uploaded. Replace it and continue.
			if (
				! ( error instanceof Error ) ||
				/** @type {any} */ ( error ).status !== 422
			) {
				throw error;
			}
			const assets = await this.listReleaseAssets( releaseId );
			const stale = assets.find( ( asset ) => asset.name === name );
			if ( ! stale ) {
				throw error;
			}
			await this.deleteReleaseAsset( stale.id );
			return upload();
		}
	}

	/**
	 * @param {number} assetId
	 */
	deleteReleaseAsset( assetId ) {
		return this.request(
			`/repos/${ this.repository }/releases/assets/${ assetId }`,
			{ method: 'DELETE' }
		);
	}

	/**
	 * @param {string} reference
	 */
	getGitReference( reference ) {
		return this.request(
			`/repos/${ this.repository }/git/ref/${ reference }`,
			{ allowNotFound: true }
		);
	}

	/**
	 * @param {string} path
	 * @param {string} content
	 */
	createGitTree( path, content ) {
		return this.request( `/repos/${ this.repository }/git/trees`, {
			method: 'POST',
			json: {
				tree: [ { path, mode: '100644', type: 'blob', content } ],
			},
		} );
	}

	/**
	 * @param {string} message
	 * @param {string} treeSha
	 * @param {string | null} parentSha
	 */
	createGitCommit( message, treeSha, parentSha ) {
		return this.request( `/repos/${ this.repository }/git/commits`, {
			method: 'POST',
			json: {
				message,
				tree: treeSha,
				parents: parentSha ? [ parentSha ] : [],
			},
		} );
	}

	/**
	 * @param {string} reference
	 * @param {string} sha
	 * @param {boolean} exists
	 */
	setGitReference( reference, sha, exists ) {
		return exists
			? this.request(
					`/repos/${ this.repository }/git/refs/${ reference }`,
					{ method: 'PATCH', json: { sha, force: true } }
			  )
			: this.request( `/repos/${ this.repository }/git/refs`, {
					method: 'POST',
					json: { ref: `refs/${ reference }`, sha },
			  } );
	}

	/**
	 * @param {string} ref
	 */
	listActionCaches( ref ) {
		return this.pages(
			`/repos/${ this.repository }/actions/caches?${ query( { ref } ) }`,
			'actions_caches'
		);
	}

	/**
	 * @param {number} cacheId
	 */
	deleteActionCache( cacheId ) {
		return this.request(
			`/repos/${ this.repository }/actions/caches/${ cacheId }`,
			{ method: 'DELETE' }
		);
	}

	/**
	 * @param {number} pullRequestNumber
	 */
	async findPreviewComment( pullRequestNumber ) {
		const comments = await this.pages(
			`/repos/${ this.repository }/issues/${ pullRequestNumber }/comments`
		);
		return (
			comments.find(
				( comment ) =>
					comment.user?.type === 'Bot' &&
					comment.body.includes( COMMENT_MARKER )
			) || null
		);
	}

	/**
	 * @param {number} pullRequestNumber
	 * @param {string} body
	 */
	createComment( pullRequestNumber, body ) {
		return this.request(
			`/repos/${ this.repository }/issues/${ pullRequestNumber }/comments`,
			{ method: 'POST', json: { body } }
		);
	}

	/**
	 * @param {number} commentId
	 * @param {string} body
	 */
	updateComment( commentId, body ) {
		return this.request(
			`/repos/${ this.repository }/issues/comments/${ commentId }`,
			{ method: 'PATCH', json: { body } }
		);
	}

	/**
	 * @param {number} pullRequestNumber
	 */
	removeLabel( pullRequestNumber ) {
		return this.request(
			`/repos/${ this.repository }/issues/${ pullRequestNumber }/labels/docs-preview`,
			{ method: 'DELETE', allowNotFound: true }
		);
	}
}
