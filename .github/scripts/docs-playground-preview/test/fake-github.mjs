// One in-memory stand-in for GitHubApi. The API client is the only boundary the
// publisher, the reuse check and the lifecycle reach the network through, so
// faking it is enough to exercise every outcome.

export const repository = 'WordPress/wordpress-develop';
export const sourceRepository = 'contributor/wordpress-develop';
export const sourceSha = 'a'.repeat( 40 );

/**
 * @param {Record<string, any>} [overrides]
 */
export function buildRun( overrides = {} ) {
	return { id: 456, run_attempt: 1, head_sha: sourceSha, ...overrides };
}

/**
 * @param {Record<string, any>} [overrides]
 */
export function pullRequest( overrides = {} ) {
	return {
		number: 123,
		head: { sha: sourceSha, repo: { full_name: sourceRepository } },
		labels: [ { name: 'docs-preview' } ],
		...overrides,
	};
}

/**
 * @param {number} id
 * @param {string} name
 * @param {string} [createdAt]
 */
export function releaseAsset(
	id,
	name,
	createdAt = '2026-08-08T13:00:00.000Z'
) {
	return { id, name, created_at: createdAt };
}

export class FakeGitHub {
	/**
	 * @param {Record<string, any>} [state]
	 */
	constructor( state = {} ) {
		this.repository = repository;
		this.run = state.run || buildRun();
		this.pullRequest =
			state.pullRequest === undefined ? pullRequest() : state.pullRequest;
		this.latestRun = state.latestRun || this.run;
		this.release = state.release === undefined ? { id: 9 } : state.release;
		/** @type {any[]} */
		this.assets = state.assets || [];
		/** @type {Map<string, any>} */
		this.assetJson = state.assetJson || new Map();
		/** @type {any[]} */
		this.comments = state.comments || [];
		/** @type {any[]} */
		this.caches = state.caches || [];
		this.gitReference = state.gitReference || null;
		/** @type {any[]} */
		this.uploads = [];
		/** @type {any[]} */
		this.deletedAssets = [];
		/** @type {any[]} */
		this.deletedCaches = [];
		/** @type {any[]} */
		this.commits = [];
		/** @type {any[]} */
		this.trees = [];
		this.labelRemovals = 0;
		this.nextAssetId = 100;
	}

	async getRun() {
		return this.run;
	}

	async findPullRequestForRun() {
		return this.pullRequest;
	}

	async getPullRequest() {
		return this.pullRequest;
	}

	async latestPullRequestBuildRun() {
		return this.latestRun;
	}

	async latestTrunkBuildRun() {
		return this.latestRun;
	}

	async getRelease() {
		return this.release;
	}

	async createRelease() {
		this.release = { id: 9 };
		return this.release;
	}

	async listReleaseAssets() {
		return [ ...this.assets ];
	}

	/**
	 * @param {number} releaseId
	 * @param {string} name
	 * @param {Uint8Array} bytes
	 * @param {string} contentType
	 */
	async uploadReleaseAsset( releaseId, name, bytes, contentType ) {
		const asset = releaseAsset(
			this.nextAssetId++,
			name,
			'2026-09-04T12:00:00.000Z'
		);
		this.uploads.push( { name, contentType, bytes } );
		this.assets.push( asset );
		if ( contentType === 'application/json' ) {
			this.assetJson.set(
				name,
				JSON.parse( Buffer.from( bytes ).toString( 'utf8' ) )
			);
		}
		return asset;
	}

	/**
	 * @param {number} assetId
	 */
	async deleteReleaseAsset( assetId ) {
		this.deletedAssets.push( assetId );
		this.assets = this.assets.filter( ( asset ) => asset.id !== assetId );
		return null;
	}

	/**
	 * @param {Record<string, any>} asset
	 */
	async readAssetJson( asset ) {
		const json = this.assetJson.get( asset.name );
		if ( ! json ) {
			throw new Error( `No published metadata for ${ asset.name }.` );
		}
		return json;
	}

	async findPreviewComment() {
		return this.comments[ 0 ] || null;
	}

	/**
	 * @param {number} pullRequestNumber
	 * @param {string} body
	 */
	async createComment( pullRequestNumber, body ) {
		this.comments.push( { id: 1, body } );
		return this.comments[ 0 ];
	}

	/**
	 * @param {number} commentId
	 * @param {string} body
	 */
	async updateComment( commentId, body ) {
		this.comments[ 0 ] = { id: commentId, body };
		return this.comments[ 0 ];
	}

	async removeLabel() {
		this.labelRemovals++;
		return null;
	}

	async getGitReference() {
		return this.gitReference;
	}

	/**
	 * @param {string} path
	 * @param {string} content
	 */
	async createGitTree( path, content ) {
		this.trees.push( { path, content } );
		return { sha: 'c'.repeat( 40 ) };
	}

	/**
	 * @param {string} message
	 * @param {string} treeSha
	 * @param {string | null} parentSha
	 */
	async createGitCommit( message, treeSha, parentSha ) {
		this.commits.push( { message, treeSha, parentSha } );
		return { sha: 'd'.repeat( 40 ) };
	}

	/**
	 * @param {string} reference
	 * @param {string} sha
	 * @param {boolean} exists
	 */
	async setGitReference( reference, sha, exists ) {
		this.gitReference = {
			ref: reference,
			object: { sha },
			existed: exists,
		};
		return this.gitReference;
	}

	async listActionCaches() {
		return [ ...this.caches ];
	}

	/**
	 * @param {number} cacheId
	 */
	async deleteActionCache( cacheId ) {
		this.deletedCaches.push( cacheId );
		return null;
	}
}
