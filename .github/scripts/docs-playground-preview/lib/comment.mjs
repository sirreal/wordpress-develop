// The single pull request comment. It is created once and updated in place, so
// every state below replaces the whole body.

import { COMMENT_MARKER } from './preview.mjs';

const SOURCE_MARKER =
	/<!-- code-reference-docs-preview-source: ([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)@([0-9a-f]{40}) -->/;

/**
 * @param {string} repository
 * @param {string} sha
 */
function commitLink( repository, sha ) {
	return `[${ sha }](https://github.com/${ repository }/commit/${ sha })`;
}

/**
 * The source marker records which commit the comment describes. The lifecycle
 * reads it back to tell an outdated comment from a current one.
 *
 * @param {string | null} repository
 * @param {string | null} sha
 */
function header( repository = null, sha = null ) {
	const marker = repository
		? `\n<!-- code-reference-docs-preview-source: ${ repository }@${ sha } -->`
		: '';
	return `${ COMMENT_MARKER }${ marker }\n## Code Reference documentation preview`;
}

/**
 * @param {unknown} body
 */
export function readPreviewCommentSource( body ) {
	const match = typeof body === 'string' ? body.match( SOURCE_MARKER ) : null;
	return match ? { repository: match[ 1 ], sha: match[ 2 ] } : null;
}

/**
 * @param {Record<string, any>} preview
 * @param {string} runUrl
 */
export function readyComment( preview, runUrl ) {
	return `${ header(
		preview.sourceRepository,
		preview.sourceSha
	) }\n\n**Status:** Ready\n\n[Open Code Reference preview](${
		preview.publication.playgroundUrl
	})\n\nSource: ${ commitLink(
		preview.sourceRepository,
		preview.sourceSha
	) }  \nPublished: ${
		preview.publication.publishedAt
	}  \n[GitHub Actions run](${ runUrl })`;
}

/**
 * @param {Record<string, any>} context
 * @param {Record<string, any> | null} previous
 */
export function failedComment( context, previous ) {
	const fallback = previous
		? `\n\n[Latest successful docs preview](${
				previous.publication.playgroundUrl
		  }) — built from ${ commitLink(
				previous.sourceRepository,
				previous.sourceSha
		  ) }.`
		: '';
	return `${ header(
		context.sourceRepository,
		context.sourceSha
	) }\n\n**Status:** Latest attempt failed\n\nThe latest attempt for ${ commitLink(
		context.sourceRepository,
		context.sourceSha
	) } failed at ${ new Date().toISOString() }. [View the GitHub Actions run](${
		context.runUrl
	}).${ fallback }`;
}

/**
 * @param {Record<string, any>} preview
 * @param {Record<string, any>} context
 */
export function staleComment( preview, context ) {
	return `${ header(
		preview.sourceRepository,
		preview.sourceSha
	) }\n\n**Status:** Stale\n\nThe latest successful preview was built from ${ commitLink(
		preview.sourceRepository,
		preview.sourceSha
	) }, but the pull request is now at ${ commitLink(
		context.sourceRepository,
		context.sourceSha
	) }.\n\n[Latest successful docs preview](${
		preview.publication.playgroundUrl
	})\n\nAdd the \`docs-preview\` label again to build the current commit.`;
}

/**
 * @param {Record<string, any>} previous
 * @param {Record<string, any>} context
 */
export function staleUnavailableComment( previous, context ) {
	return `${ header(
		previous.repository,
		previous.sha
	) }\n\n**Status:** Stale\n\nThe latest preview attempt was for ${ commitLink(
		previous.repository,
		previous.sha
	) }, but no healthy docs preview is available. The pull request is now at ${ commitLink(
		context.sourceRepository,
		context.sourceSha
	) }.\n\nAdd the \`docs-preview\` label again to build the current commit.`;
}

export function expiredComment() {
	return `${ header() }\n\n**Status:** Expired\n\nThis pull request is closed or merged. Its Code Reference preview expired and is no longer live.`;
}
