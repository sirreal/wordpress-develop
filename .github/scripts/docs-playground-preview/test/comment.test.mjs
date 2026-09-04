import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
	expiredComment,
	failedComment,
	readPreviewCommentSource,
	readyComment,
	staleComment,
	staleUnavailableComment,
} from '../lib/comment.mjs';
import { COMMENT_MARKER } from '../lib/preview.mjs';

const sourceRepository = 'contributor/wordpress-develop';
const sourceSha = 'a'.repeat( 40 );
const previousSha = 'b'.repeat( 40 );
const runUrl =
	'https://github.com/WordPress/wordpress-develop/actions/runs/456';
const preview = {
	sourceRepository,
	sourceSha,
	publication: {
		publishedAt: '2026-09-04T12:00:00.000Z',
		playgroundUrl: 'https://playground.wordpress.net/?blueprint-url=x',
	},
};
const context = { sourceRepository, sourceSha, runUrl };

test( 'every state carries the marker that identifies the one preview comment', () => {
	for ( const body of [
		readyComment( preview, runUrl ),
		failedComment( context, null ),
		staleComment( preview, context ),
		staleUnavailableComment(
			{ repository: sourceRepository, sha: previousSha },
			context
		),
		expiredComment(),
	] ) {
		assert.ok( body.includes( COMMENT_MARKER ) );
	}
} );

test( 'a ready comment links the preview and its source commit', () => {
	const body = readyComment( preview, runUrl );

	assert.match( body, /\*\*Status:\*\* Ready/ );
	assert.ok(
		body.includes(
			'[Open Code Reference preview](https://playground.wordpress.net/?blueprint-url=x)'
		)
	);
	assert.ok( body.includes( `/commit/${ sourceSha }` ) );
	assert.ok( body.includes( runUrl ) );
} );

test( 'a failure comment offers the last working preview when there is one', () => {
	assert.doesNotMatch(
		failedComment( context, null ),
		/Latest successful docs preview/
	);
	assert.match(
		failedComment( context, {
			sourceRepository,
			sourceSha: previousSha,
			publication: {
				playgroundUrl:
					'https://playground.wordpress.net/?blueprint-url=y',
			},
		} ),
		/\[Latest successful docs preview\]\(https:\/\/playground\.wordpress\.net\/\?blueprint-url=y\)/
	);
} );

test( 'the source marker survives a round trip and expired comments carry none', () => {
	assert.deepEqual(
		readPreviewCommentSource( readyComment( preview, runUrl ) ),
		{
			repository: sourceRepository,
			sha: sourceSha,
		}
	);
	assert.equal( readPreviewCommentSource( expiredComment() ), null );
	assert.equal( readPreviewCommentSource( undefined ), null );
} );

test( 'a stale comment names both the built commit and the current one', () => {
	const body = staleComment( preview, {
		sourceRepository,
		sourceSha: previousSha,
	} );

	assert.match( body, /\*\*Status:\*\* Stale/ );
	assert.ok( body.includes( `/commit/${ sourceSha }` ) );
	assert.ok( body.includes( `/commit/${ previousSha }` ) );
	assert.match( body, /Add the `docs-preview` label again/ );
} );
