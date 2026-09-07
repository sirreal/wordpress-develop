// Names, URLs and metadata shared across the preview pipeline. A "preview" is
// one Playground snapshot published as a release asset next to a JSON metadata
// asset of the same stem.

export const RELEASE_TAG = 'code-reference-playground-preview';
export const COMMENT_MARKER = '<!-- code-reference-docs-preview -->';
export const TRUNK_POINTER_ASSET = 'code-reference-trunk.json';
export const TRUNK_POINTER_REF = 'heads/docs-preview-code-reference';

// The build writes these two files into the handoff directory that the publish
// job downloads, and the publisher reads nothing else out of it. The published
// asset is renamed after the workflow run, so the build never names it.
export const HANDOFF_METADATA = 'build.json';
export const HANDOFF_SNAPSHOT = 'snapshot.zip';

// What the build writes as `handoffType` when it reuses a published snapshot
// for the same commit instead of building one.
export const HANDOFF_REUSE = 'reuse';

const SNAPSHOT_BYTES_LIMIT = 104857600;
const PLAYGROUND_ORIGIN = 'https://playground.wordpress.net';
const TRUNK_POINTER_BRANCH = TRUNK_POINTER_REF.slice( 'heads/'.length );
const CORS_PROXY = 'https://wordpress-playground-cors-proxy.net/';

/**
 * Every asset is named after the run that produced it, so one build cannot
 * write over the assets of another pull request or of trunk. Finding the assets
 * of a pull request, or of one of its commits, means matching this prefix.
 *
 * @param {number | null} pullRequestNumber
 * @param {string} [sourceSha]
 */
export function assetPrefix( pullRequestNumber, sourceSha ) {
	const scope =
		pullRequestNumber === null ? 'trunk' : `pr-${ pullRequestNumber }`;
	return `code-reference-${ scope }-${ sourceSha ? `${ sourceSha }-` : '' }`;
}

/**
 * @param {Record<string, any>} context
 */
export function snapshotAssetName( context ) {
	return `${ assetPrefix( context.pullRequestNumber, context.sourceSha ) }${
		context.workflowRunId
	}-${ context.workflowRunAttempt }.zip`;
}

/**
 * One limit checked twice: the build refuses to hand over an oversized
 * snapshot, and the publisher refuses to upload one.
 *
 * @param {number} bytes
 */
export function assertSnapshotFits( bytes ) {
	if ( bytes > SNAPSHOT_BYTES_LIMIT ) {
		throw new Error(
			`The snapshot is ${ bytes } bytes; the limit is ${ SNAPSHOT_BYTES_LIMIT }.`
		);
	}
}

/**
 * @param {string} snapshotName
 */
export function metadataAssetName( snapshotName ) {
	return `${ snapshotName.slice( 0, -'.zip'.length ) }.json`;
}

/**
 * The newest snapshot under this prefix that also has its metadata asset. The
 * snapshot is uploaded first, so a run that stopped between the two uploads
 * leaves a snapshot nothing should point at.
 *
 * @param {any[]} assets
 * @param {string} prefix
 * @returns {Record<string, any> | null}
 */
export function findLatestSnapshot( assets, prefix ) {
	return (
		assets
			.filter(
				( asset ) =>
					asset.name.startsWith( prefix ) &&
					asset.name.endsWith( '.zip' ) &&
					assets.some(
						( sibling ) =>
							sibling.name === metadataAssetName( asset.name )
					)
			)
			.sort( ( left, right ) =>
				right.created_at.localeCompare( left.created_at )
			)[ 0 ] || null
	);
}

/**
 * Playground fetches assets from the browser, where github.com sends no CORS
 * headers, so every published URL is read through the Playground proxy.
 *
 * @param {string} publicUrl
 */
function corsProxyUrl( publicUrl ) {
	return `${ CORS_PROXY }${ publicUrl }`;
}

/**
 * @param {string} snapshotUrl
 * @param {string} phpVersion
 * @param {string} wordPressVersion
 * @param {string} description
 */
function createLaunchBlueprint(
	snapshotUrl,
	phpVersion,
	wordPressVersion,
	description
) {
	return {
		meta: {
			title: 'WordPress Core Code Reference preview',
			author: 'WordPress',
			description,
		},
		preferredVersions: { php: phpVersion, wp: wordPressVersion },
		landingPage: '/reference/',
		login: false,
		features: { networking: false },
		steps: [
			{
				step: 'unzip',
				zipFile: { resource: 'url', url: corsProxyUrl( snapshotUrl ) },
				extractToPath: '/',
			},
		],
	};
}

/**
 * The repository README links this URL, so it must stay stable across trunk
 * builds. The Blueprint behind it names the current trunk snapshot.
 *
 * @param {string} repository
 */
function trunkStableBlueprintUrl( repository ) {
	return `https://raw.githubusercontent.com/${ repository }/${ TRUNK_POINTER_BRANCH }/${ TRUNK_POINTER_ASSET }`;
}

/**
 * @param {string} repository
 */
function trunkPlaygroundUrl( repository ) {
	return `${ PLAYGROUND_ORIGIN }/?blueprint-url=${ encodeURIComponent(
		corsProxyUrl( trunkStableBlueprintUrl( repository ) )
	) }`;
}

/**
 * Identity comes from the workflow run, description from the build handoff, so
 * a build cannot publish metadata that names a commit it did not build.
 *
 * @param {Record<string, any>} handoff
 * @param {Record<string, any>} context
 * @param {Record<string, any>} snapshot
 */
export function createPublishedMetadata( handoff, context, snapshot ) {
	const snapshotUrl = `https://github.com/${ context.repository }/releases/download/${ RELEASE_TAG }/${ snapshot.filename }`;
	const blueprint = createLaunchBlueprint(
		snapshotUrl,
		handoff.phpVersion,
		handoff.resolvedWordPressBeta?.version,
		'Complete Core Code Reference generated from an exact commit.'
	);
	/** @type {Record<string, string>} */
	const publication = {
		publishedAt: new Date().toISOString(),
		snapshotUrl,
		playgroundUrl: `${ PLAYGROUND_ORIGIN }/?blueprint-url=data:application/json,${ encodeURIComponent(
			JSON.stringify( blueprint )
		) }`,
	};
	if ( context.pullRequestNumber === null ) {
		// Trunk metadata records the stable link that the repository README uses.
		publication.stableBlueprintUrl = trunkStableBlueprintUrl(
			context.repository
		);
		publication.stablePlaygroundUrl = trunkPlaygroundUrl(
			context.repository
		);
	}
	return {
		schemaVersion: 1,
		sourceRepository: context.sourceRepository,
		pullRequestNumber: context.pullRequestNumber,
		sourceSha: context.sourceSha,
		workflowRunId: context.workflowRunId,
		workflowRunAttempt: context.workflowRunAttempt,
		runUrl: context.runUrl,
		resolvedWordPressBeta: handoff.resolvedWordPressBeta,
		phpVersion: handoff.phpVersion,
		dependencyManifestDigest: handoff.dependencyManifestDigest,
		generationTimestamp: handoff.generationTimestamp,
		snapshotFilename: snapshot.filename,
		snapshotBytes: snapshot.bytes,
		snapshotSha256: snapshot.sha256,
		publication,
	};
}

/**
 * @param {Record<string, any>} published
 */
export function createTrunkBlueprint( published ) {
	return createLaunchBlueprint(
		published.publication.snapshotUrl,
		published.phpVersion,
		published.resolvedWordPressBeta?.version,
		`Latest successful Core Code Reference from ${ published.sourceRepository }@${ published.sourceSha }. Generated ${ published.generationTimestamp }. ${ published.runUrl }`
	);
}

/**
 * The newest published preview for a pull request, used to keep a working link
 * in the comment when the current attempt has nothing to show.
 *
 * @param {Record<string, any>} api
 * @param {number} pullRequestNumber
 * @returns {Promise<Record<string, any> | null>}
 */
export async function findLatestPublishedPreview( api, pullRequestNumber ) {
	const release = await api.getRelease();
	const assets = release ? await api.listReleaseAssets( release.id ) : [];
	const snapshot = findLatestSnapshot(
		assets,
		assetPrefix( pullRequestNumber )
	);
	if ( ! snapshot ) {
		return null;
	}
	// findLatestSnapshot only returns a snapshot whose metadata asset exists.
	const metadata = assets.find(
		( /** @type {Record<string, any>} */ asset ) =>
			asset.name === metadataAssetName( snapshot.name )
	);
	try {
		return await api.readAssetJson( metadata );
	} catch ( error ) {
		// A concurrent publication can delete these assets between the
		// listing and the read; the caller then reports no preview.
		process.stderr.write(
			`::warning::Cannot read ${ metadata.name }: ${
				error instanceof Error ? error.message : String( error )
			}\n`
		);
		return null;
	}
}
