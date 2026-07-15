#!/usr/bin/env node
'use strict';

const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const net = require( 'node:net' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const readline = require( 'node:readline' );
const { spawn, spawnSync } = require( 'node:child_process' );

const SCRIPT_DIR = __dirname;
const PINNED_CHROME_VERSION = fs.readFileSync( path.join( SCRIPT_DIR, 'VERSION' ), 'utf8' ).trim();
const HTML_NS = 'http://www.w3.org/1999/xhtml';
const SVG_NS = 'http://www.w3.org/2000/svg';
const MATH_NS = 'http://www.w3.org/1998/Math/MathML';

function parseArgs( argv ) {
	const options = { engine: 'chrome' };
	for ( let i = 2; i < argv.length; i++ ) {
		const arg = argv[ i ];
		if ( ! arg.startsWith( '--' ) ) {
			throw new Error( `Unexpected argument: ${ arg }` );
		}
		const name = arg.slice( 2 );
		if ( [ 'help', 'serve', 'version' ].includes( name ) ) {
			options[ name ] = true;
			continue;
		}
		if ( i + 1 >= argv.length ) {
			throw new Error( `Missing value for --${ name }.` );
		}
		options[ name ] = argv[ ++i ];
	}
	if ( 'chrome' !== options.engine ) {
		throw new Error( `Expected --engine chrome; got ${ options.engine }.` );
	}
	if ( options.socket && ! options.serve ) {
		throw new Error( '--socket requires --serve.' );
	}
	return options;
}

function localChromeExecutable() {
	let platform;
	let archiveDirectory;
	let executableRelative;
	if ( 'darwin' === process.platform && 'arm64' === process.arch ) {
		platform = 'mac-arm64';
		archiveDirectory = 'chrome-mac-arm64';
		executableRelative = 'Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing';
	} else if ( 'darwin' === process.platform && 'x64' === process.arch ) {
		platform = 'mac-x64';
		archiveDirectory = 'chrome-mac-x64';
		executableRelative = 'Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing';
	} else if ( 'linux' === process.platform && 'x64' === process.arch ) {
		platform = 'linux64';
		archiveDirectory = 'chrome-linux64';
		executableRelative = 'chrome';
	} else {
		return null;
	}
	const installRoot = process.env.HTML_API_FUZZ_CHROME_INSTALL_ROOT || path.join( SCRIPT_DIR, '.chrome-for-testing' );
	return path.join( installRoot, PINNED_CHROME_VERSION, platform, archiveDirectory, executableRelative );
}

function chromeExecutable( options ) {
	const configured = options[ 'chrome-executable' ] || process.env.HTML_API_FUZZ_CHROME_EXECUTABLE;
	return configured ? path.resolve( configured ) : localChromeExecutable();
}

function readInstalledVersion( executable ) {
	if ( ! executable || ! fs.existsSync( executable ) ) {
		return null;
	}
	const checked = spawnSync( executable, [ '--version' ], { encoding: 'utf8', timeout: 5000 } );
	if ( checked.error || 0 !== checked.status ) {
		return null;
	}
	const match = `${ checked.stdout }${ checked.stderr }`.match( /([0-9]+(?:\.[0-9]+){3})/ );
	return match ? match[ 1 ] : null;
}

function configuredMetadata( options ) {
	const executable = chromeExecutable( options );
	const installedVersion = readInstalledVersion( executable );
	const versionMatchesPin = installedVersion === PINNED_CHROME_VERSION;
	const metadata = {
		kind: 'chrome-cdp',
		engine: 'chrome',
		available: Boolean( executable && versionMatchesPin ),
		pinnedChromeVersion: PINNED_CHROME_VERSION,
		chromeExecutable: executable,
		nodeVersion: process.version,
		script: __filename,
		cdpTransport: 'remote-debugging-websocket',
	};
	if ( installedVersion ) {
		metadata.chromeVersion = installedVersion;
		metadata.browserVersion = installedVersion;
	}
	if ( installedVersion && ! versionMatchesPin ) {
		metadata.error = `Chrome version ${ installedVersion } does not match the required pin ${ PINNED_CHROME_VERSION } at ${ executable }.`;
	} else if ( ! executable ) {
		metadata.error = `Chrome for Testing is not available for ${ process.platform }/${ process.arch }.`;
	} else if ( ! fs.existsSync( executable ) ) {
		metadata.error = `Pinned Chrome for Testing is not installed at ${ executable }. Run ${ path.join( SCRIPT_DIR, 'install.sh' ) }.`;
	} else if ( ! installedVersion ) {
		metadata.error = `Could not execute Chrome for Testing at ${ executable }.`;
	}
	return metadata;
}

class CdpWebSocket {
	constructor( socket, initialData = Buffer.alloc( 0 ) ) {
		this.socket = socket;
		this.buffer = Buffer.alloc( 0 );
		this.fragmentOpcode = null;
		this.fragments = [];
		this.messageHandler = () => {};
		this.closeHandler = () => {};
		this.closed = false;
		socket.on( 'data', ( data ) => this.consume( data ) );
		socket.on( 'close', () => this.handleClose( new Error( 'CDP WebSocket closed.' ) ) );
		socket.on( 'error', ( error ) => this.handleClose( error ) );
		if ( initialData.length ) {
			this.consume( initialData );
		}
	}

	onMessage( handler ) {
		this.messageHandler = handler;
	}

	onClose( handler ) {
		this.closeHandler = handler;
	}

	handleClose( error ) {
		if ( this.closed ) {
			return;
		}
		this.closed = true;
		this.closeHandler( error );
	}

	consume( data ) {
		this.buffer = Buffer.concat( [ this.buffer, data ] );
		while ( this.buffer.length >= 2 ) {
			const first = this.buffer[ 0 ];
			const second = this.buffer[ 1 ];
			const final = Boolean( first & 0x80 );
			const opcode = first & 0x0f;
			const masked = Boolean( second & 0x80 );
			let length = second & 0x7f;
			let offset = 2;
			if ( 126 === length ) {
				if ( this.buffer.length < 4 ) {
					return;
				}
				length = this.buffer.readUInt16BE( 2 );
				offset = 4;
			} else if ( 127 === length ) {
				if ( this.buffer.length < 10 ) {
					return;
				}
				const longLength = this.buffer.readBigUInt64BE( 2 );
				if ( longLength > BigInt( Number.MAX_SAFE_INTEGER ) ) {
					this.handleClose( new Error( 'CDP WebSocket frame is too large.' ) );
					this.socket.destroy();
					return;
				}
				length = Number( longLength );
				offset = 10;
			}
			const maskBytes = masked ? 4 : 0;
			if ( this.buffer.length < offset + maskBytes + length ) {
				return;
			}
			let payload = Buffer.from( this.buffer.subarray( offset + maskBytes, offset + maskBytes + length ) );
			if ( masked ) {
				const mask = this.buffer.subarray( offset, offset + 4 );
				for ( let i = 0; i < payload.length; i++ ) {
					payload[ i ] ^= mask[ i % 4 ];
				}
			}
			this.buffer = this.buffer.subarray( offset + maskBytes + length );
			if ( 0x8 === opcode ) {
				this.sendFrame( 0x8, payload );
				this.socket.end();
				return;
			}
			if ( 0x9 === opcode ) {
				this.sendFrame( 0xA, payload );
				continue;
			}
			if ( 0xA === opcode ) {
				continue;
			}
			if ( 0x1 === opcode || 0x2 === opcode ) {
				this.fragmentOpcode = opcode;
				this.fragments = [ payload ];
			} else if ( 0x0 === opcode && null !== this.fragmentOpcode ) {
				this.fragments.push( payload );
			} else {
				continue;
			}
			if ( final ) {
				const message = Buffer.concat( this.fragments );
				const messageOpcode = this.fragmentOpcode;
				this.fragmentOpcode = null;
				this.fragments = [];
				if ( 0x1 === messageOpcode ) {
					this.messageHandler( message.toString( 'utf8' ) );
				}
			}
		}
	}

	sendFrame( opcode, payload ) {
		if ( this.closed ) {
			throw new Error( 'Cannot write to a closed CDP WebSocket.' );
		}
		payload = Buffer.isBuffer( payload ) ? payload : Buffer.from( payload );
		let header;
		if ( payload.length < 126 ) {
			header = Buffer.alloc( 2 );
			header[ 1 ] = 0x80 | payload.length;
		} else if ( payload.length <= 0xffff ) {
			header = Buffer.alloc( 4 );
			header[ 1 ] = 0x80 | 126;
			header.writeUInt16BE( payload.length, 2 );
		} else {
			header = Buffer.alloc( 10 );
			header[ 1 ] = 0x80 | 127;
			header.writeBigUInt64BE( BigInt( payload.length ), 2 );
		}
		header[ 0 ] = 0x80 | opcode;
		const mask = crypto.randomBytes( 4 );
		const masked = Buffer.allocUnsafe( payload.length );
		for ( let i = 0; i < payload.length; i++ ) {
			masked[ i ] = payload[ i ] ^ mask[ i % 4 ];
		}
		this.socket.write( Buffer.concat( [ header, mask, masked ] ) );
	}

	sendJson( value ) {
		this.sendFrame( 0x1, Buffer.from( JSON.stringify( value ), 'utf8' ) );
	}

	close() {
		if ( ! this.closed ) {
			this.sendFrame( 0x8, Buffer.alloc( 0 ) );
			this.socket.end();
		}
	}
}

function connectWebSocket( endpoint ) {
	return new Promise( ( resolve, reject ) => {
		const url = new URL( endpoint );
		if ( 'ws:' !== url.protocol ) {
			reject( new Error( `Unsupported CDP WebSocket URL: ${ endpoint }` ) );
			return;
		}
		const key = crypto.randomBytes( 16 ).toString( 'base64' );
		const expectedAccept = crypto.createHash( 'sha1' )
			.update( `${ key }258EAFA5-E914-47DA-95CA-C5AB0DC85B11` )
			.digest( 'base64' );
		const socket = net.createConnection( { host: url.hostname, port: Number( url.port ) } );
		let headers = Buffer.alloc( 0 );
		let settled = false;
		const fail = ( error ) => {
			if ( ! settled ) {
				settled = true;
				socket.destroy();
				reject( error );
			}
		};
		socket.once( 'error', fail );
		socket.once( 'connect', () => {
			const target = `${ url.pathname }${ url.search }`;
			socket.write(
				`GET ${ target } HTTP/1.1\r\n` +
				`Host: ${ url.host }\r\n` +
				'Upgrade: websocket\r\n' +
				'Connection: Upgrade\r\n' +
				`Sec-WebSocket-Key: ${ key }\r\n` +
				'Sec-WebSocket-Version: 13\r\n\r\n'
			);
		} );
		const onData = ( data ) => {
			headers = Buffer.concat( [ headers, data ] );
			const boundary = headers.indexOf( '\r\n\r\n' );
			if ( -1 === boundary ) {
				return;
			}
			const headerText = headers.subarray( 0, boundary ).toString( 'latin1' );
			const remainder = headers.subarray( boundary + 4 );
			if ( ! /^HTTP\/1\.1 101\b/.test( headerText ) ) {
				fail( new Error( `CDP WebSocket handshake failed: ${ headerText.split( '\r\n' )[ 0 ] }` ) );
				return;
			}
			const acceptMatch = headerText.match( /^Sec-WebSocket-Accept:\s*(.+)$/im );
			if ( ! acceptMatch || acceptMatch[ 1 ].trim() !== expectedAccept ) {
				fail( new Error( 'CDP WebSocket handshake returned an invalid accept key.' ) );
				return;
			}
			settled = true;
			socket.removeListener( 'data', onData );
			socket.removeListener( 'error', fail );
			resolve( new CdpWebSocket( socket, remainder ) );
		};
		socket.on( 'data', onData );
	} );
}

class CdpClient {
	constructor( websocket ) {
		this.websocket = websocket;
		this.nextId = 0;
		this.pending = new Map();
		this.waiters = [];
		websocket.onMessage( ( message ) => this.receive( message ) );
		websocket.onClose( ( error ) => this.failAll( error ) );
	}

	receive( message ) {
		let decoded;
		try {
			decoded = JSON.parse( message );
		} catch ( error ) {
			this.failAll( new Error( `Chrome returned invalid CDP JSON: ${ error.message }` ) );
			return;
		}
		if ( decoded.id && this.pending.has( decoded.id ) ) {
			const pending = this.pending.get( decoded.id );
			this.pending.delete( decoded.id );
			clearTimeout( pending.timer );
			if ( decoded.error ) {
				const error = new Error( `CDP ${ pending.method } failed: ${ decoded.error.message }` );
				error.code = decoded.error.code;
				pending.reject( error );
			} else {
				pending.resolve( decoded.result || {} );
			}
			return;
		}
		if ( decoded.method ) {
			for ( const waiter of [ ...this.waiters ] ) {
				if ( waiter.method === decoded.method && ( ! waiter.sessionId || waiter.sessionId === decoded.sessionId ) ) {
					this.waiters.splice( this.waiters.indexOf( waiter ), 1 );
					clearTimeout( waiter.timer );
					waiter.resolve( decoded.params || {} );
				}
			}
		}
	}

	send( method, params = {}, sessionId = undefined, timeoutMs = 15000 ) {
		const id = ++this.nextId;
		return new Promise( ( resolve, reject ) => {
			const timer = setTimeout( () => {
				this.pending.delete( id );
				reject( new Error( `CDP ${ method } timed out.` ) );
			}, timeoutMs );
			this.pending.set( id, { method, resolve, reject, timer } );
			const message = { id, method, params };
			if ( sessionId ) {
				message.sessionId = sessionId;
			}
			try {
				this.websocket.sendJson( message );
			} catch ( error ) {
				clearTimeout( timer );
				this.pending.delete( id );
				reject( error );
			}
		} );
	}

	waitFor( method, sessionId, timeoutMs = 15000 ) {
		return new Promise( ( resolve, reject ) => {
			const waiter = { method, sessionId, resolve, reject, timer: null };
			waiter.timer = setTimeout( () => {
				this.waiters.splice( this.waiters.indexOf( waiter ), 1 );
				reject( new Error( `CDP event ${ method } timed out.` ) );
			}, timeoutMs );
			this.waiters.push( waiter );
		} );
	}

	failAll( error ) {
		for ( const pending of this.pending.values() ) {
			clearTimeout( pending.timer );
			pending.reject( error );
		}
		this.pending.clear();
		for ( const waiter of this.waiters ) {
			clearTimeout( waiter.timer );
			waiter.reject( error );
		}
		this.waiters = [];
	}

	close() {
		this.websocket.close();
	}
}

function browserRender( args ) {
	const HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';
	const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
	const MATH_NAMESPACE = 'http://www.w3.org/1998/Math/MathML';
	const XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';
	const XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';
	const XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';
	let nodeCount = 0;
	const encoder = new TextEncoder();

	function escapeScalar( value ) {
		let output = '';
		for ( const character of String( value ) ) {
			switch ( character ) {
				case '\n': output += '\\n'; break;
				case '\r': output += '\\r'; break;
				case '\t': output += '\\t'; break;
				case '\0': output += '\\0'; break;
				case '\\': output += '\\\\'; break;
				case '"': output += '\\"'; break;
				default: {
					const code = character.codePointAt( 0 );
					output += code < 0x20 || 0x7f === code
						? `\\x${ code.toString( 16 ).toUpperCase().padStart( 2, '0' ) }`
						: character;
				}
			}
		}
		return output;
	}

	function compareUtf8( left, right ) {
		const a = encoder.encode( left );
		const b = encoder.encode( right );
		const length = Math.min( a.length, b.length );
		for ( let i = 0; i < length; i++ ) {
			if ( a[ i ] !== b[ i ] ) {
				return a[ i ] - b[ i ];
			}
		}
		return a.length - b.length;
	}

	function compareDisplayNames( left, right ) {
		const leftHasColon = left.includes( ':' );
		const rightHasColon = right.includes( ':' );
		if ( leftHasColon !== rightHasColon ) {
			return leftHasColon ? 1 : -1;
		}
		const leftHasSpace = left.includes( ' ' );
		const rightHasSpace = right.includes( ' ' );
		if ( leftHasSpace !== rightHasSpace ) {
			return leftHasSpace ? 1 : -1;
		}
		return compareUtf8( left, right );
	}

	function elementName( element ) {
		if ( HTML_NAMESPACE === element.namespaceURI ) {
			return element.localName.toLowerCase();
		}
		if ( SVG_NAMESPACE === element.namespaceURI ) {
			return `svg ${ element.localName }`;
		}
		if ( MATH_NAMESPACE === element.namespaceURI ) {
			return `math ${ element.localName }`;
		}
		return element.nodeName;
	}

	function attributeName( attribute ) {
		if ( XLINK_NAMESPACE === attribute.namespaceURI ) {
			return `xlink ${ attribute.localName }`;
		}
		if ( XML_NAMESPACE === attribute.namespaceURI ) {
			return `xml ${ attribute.localName }`;
		}
		if ( XMLNS_NAMESPACE === attribute.namespaceURI ) {
			return `xmlns ${ attribute.localName }`;
		}
		return attribute.name;
	}

	function renderAttributes( element, indent ) {
		const records = Array.from( element.attributes, ( attribute ) => {
			const displayName = attributeName( attribute );
			return {
				sortName: escapeScalar( displayName ),
				renderName: escapeScalar( displayName ),
				value: escapeScalar( attribute.value ),
			};
		} );
		records.sort( ( left, right ) => {
			const sorted = compareDisplayNames( left.sortName, right.sortName );
			return 0 !== sorted ? sorted : compareDisplayNames( left.renderName, right.renderName );
		} );
		return records.map(
			( record ) => `${ '  '.repeat( indent ) }${ record.renderName }="${ record.value }"\n`
		).join( '' );
	}

	function renderNode( node, indent ) {
		nodeCount++;
		if ( nodeCount > args.maxNodes ) {
			const error = new Error( 'DOM node limit exceeded.' );
			error.failureClass = 'node-limit-exceeded';
			throw error;
		}
		switch ( node.nodeType ) {
			case Node.DOCUMENT_TYPE_NODE: {
				let output = `<!DOCTYPE ${ escapeScalar( node.name ) }`;
				if ( node.publicId || node.systemId ) {
					output += ` "${ escapeScalar( node.publicId ) }" "${ escapeScalar( node.systemId ) }"`;
				}
				return `${ output }>\n`;
			}
			case Node.ELEMENT_NODE: {
				let output = `${ '  '.repeat( indent ) }<${ escapeScalar( elementName( node ) ) }>\n`;
				output += renderAttributes( node, indent + 1 );
				if ( HTML_NAMESPACE === node.namespaceURI && 'template' === node.localName ) {
					output += `${ '  '.repeat( indent + 1 ) }content\n`;
					for ( const child of node.content.childNodes ) {
						output += renderNode( child, indent + 2 );
					}
					return output;
				}
				for ( const child of node.childNodes ) {
					output += renderNode( child, indent + 1 );
				}
				return output;
			}
			case Node.TEXT_NODE:
			case Node.CDATA_SECTION_NODE:
				return '' === node.nodeValue
					? ''
					: `${ '  '.repeat( indent ) }"${ escapeScalar( node.nodeValue ) }"\n`;
			case Node.COMMENT_NODE:
				return `${ '  '.repeat( indent ) }<!-- ${ escapeScalar( node.nodeValue ) } -->\n`;
			default:
				return '';
		}
	}

	let roots;
	if ( 'full-document' === args.mode ) {
		roots = document.childNodes;
	} else {
		const owner = document.implementation.createHTMLDocument( '' );
		const lower = args.context.toLowerCase();
		let contextElement;
		if ( 'svg' === lower ) {
			contextElement = owner.createElementNS( SVG_NAMESPACE, 'svg' );
		} else if ( 'math' === lower ) {
			contextElement = owner.createElementNS( MATH_NAMESPACE, 'math' );
		} else {
			contextElement = owner.createElementNS( HTML_NAMESPACE, lower );
		}
		const range = owner.createRange();
		range.selectNodeContents( contextElement );
		const fragment = range.createContextualFragment( args.html );
		roots = fragment.childNodes;
	}

	let tree = '';
	for ( const child of roots ) {
		tree += renderNode( child, 0 );
	}
	return { tree: `${ tree }\n`, nodeCount };
}

class ChromeOracle {
	constructor( options ) {
		this.options = options;
		this.metadata = configuredMetadata( options );
		this.chrome = null;
		this.userDataDirectory = null;
		this.websocket = null;
		this.cdp = null;
		this.targetId = null;
		this.sessionId = null;
		this.startPromise = null;
		this.renderQueue = Promise.resolve();
		this.closing = false;
	}

	configuredMetadata() {
		return { ...this.metadata };
	}

	async start() {
		if ( this.cdp && this.sessionId ) {
			return;
		}
		if ( this.startPromise ) {
			return this.startPromise;
		}
		this.startPromise = this.startChrome();
		try {
			await this.startPromise;
		} finally {
			this.startPromise = null;
		}
	}

	async startChrome() {
		if ( false === this.metadata.available ) {
			const error = new Error( this.metadata.error || 'Pinned Chrome for Testing is unavailable.' );
			error.failureClass = 'oracle-unavailable';
			throw error;
		}
		this.userDataDirectory = fs.mkdtempSync( path.join( os.tmpdir(), 'html-api-fuzz-chrome-' ) );
		const args = [
			'--headless=new',
			'--remote-debugging-port=0',
			'--remote-allow-origins=*',
			`--user-data-dir=${ this.userDataDirectory }`,
			'--no-first-run',
			'--no-default-browser-check',
			'--disable-background-networking',
			'--disable-component-update',
			'--disable-domain-reliability',
			'--disable-features=OptimizationHints,MediaRouter,Translate',
			'--disable-sync',
			'--metrics-recording-only',
			'--mute-audio',
			'--no-pings',
			'--password-store=basic',
			'--use-mock-keychain',
			'about:blank',
		];
		if ( 'linux' === process.platform && 0 === process.getuid?.() ) {
			args.unshift( '--no-sandbox' );
		}
		this.chrome = spawn( this.metadata.chromeExecutable, args, { stdio: [ 'ignore', 'ignore', 'pipe' ] } );
		this.metadata.browserPid = this.chrome.pid;
		this.metadata.browserInstanceId = crypto.randomUUID();
		const endpoint = await new Promise( ( resolve, reject ) => {
			let stderr = '';
			let settled = false;
			const timer = setTimeout( () => finish( new Error( `Chrome did not expose a CDP endpoint. ${ stderr.trim() }` ) ), 15000 );
			const finish = ( error, value ) => {
				if ( settled ) {
					return;
				}
				settled = true;
				clearTimeout( timer );
				if ( error ) {
					reject( error );
				} else {
					resolve( value );
				}
			};
			this.chrome.once( 'error', finish );
			this.chrome.once( 'exit', ( code, signal ) => finish( new Error( `Chrome exited before CDP startup (code ${ code }, signal ${ signal }). ${ stderr.trim() }` ) ) );
			this.chrome.stderr.on( 'data', ( chunk ) => {
				stderr = `${ stderr }${ chunk.toString( 'utf8' ) }`.slice( -16384 );
				const match = stderr.match( /DevTools listening on (ws:\/\/[^\s]+)/ );
				if ( match ) {
					finish( null, match[ 1 ] );
				}
			} );
		} );

		this.websocket = await connectWebSocket( endpoint );
		this.cdp = new CdpClient( this.websocket );
		const browserVersion = await this.cdp.send( 'Browser.getVersion' );
		this.metadata.available = true;
		this.metadata.browserVersion = ( browserVersion.product || '' ).replace( /^[^/]+\//, '' ) || this.metadata.chromeVersion;
		this.metadata.chromeVersion = this.metadata.browserVersion;
		this.metadata.cdpProtocolVersion = browserVersion.protocolVersion;
		const target = await this.cdp.send( 'Target.createTarget', { url: 'about:blank', background: true } );
		this.targetId = target.targetId;
		const attached = await this.cdp.send( 'Target.attachToTarget', { targetId: this.targetId, flatten: true } );
		this.sessionId = attached.sessionId;
		await this.cdp.send( 'Page.enable', {}, this.sessionId );
		await this.cdp.send( 'Runtime.enable', {}, this.sessionId );
		await this.cdp.send( 'Network.enable', {}, this.sessionId );
		await this.cdp.send( 'Network.setCacheDisabled', { cacheDisabled: true }, this.sessionId );
		await this.cdp.send( 'Network.setBypassServiceWorker', { bypass: true }, this.sessionId );
		await this.cdp.send( 'Network.setBlockedURLs', {
			urls: [ 'http://*', 'https://*', 'ftp://*', 'file://*', 'ws://*', 'wss://*' ],
		}, this.sessionId );
		await this.cdp.send( 'Browser.setDownloadBehavior', { behavior: 'deny' } );
	}

	async navigateWithScriptsDisabled( dataUrl ) {
		await this.cdp.send( 'Emulation.setScriptExecutionDisabled', { value: true }, this.sessionId );
		const loaded = this.cdp.waitFor( 'Page.loadEventFired', this.sessionId );
		const navigation = await this.cdp.send( 'Page.navigate', { url: dataUrl }, this.sessionId );
		if ( navigation.errorText ) {
			throw new Error( `Chrome navigation failed: ${ navigation.errorText }` );
		}
		await loaded;
		await this.cdp.send( 'Page.stopLoading', {}, this.sessionId );
	}

	render( request ) {
		const work = this.renderQueue.then( () => this.renderNow( request ) );
		this.renderQueue = work.catch( () => {} );
		return work;
	}

	async renderNow( request ) {
		await this.start();
		const mode = request.mode || 'fragment-body';
		if ( ! [ 'full-document', 'fragment-body' ].includes( mode ) ) {
			throw new Error( `Unsupported parse mode: ${ mode }` );
		}
		const context = request.context || 'body';
		const maxNodes = Number.parseInt( request.maxNodes || 3000, 10 );
		if ( ! Number.isSafeInteger( maxNodes ) || maxNodes < 1 ) {
			throw new Error( 'maxNodes must be a positive integer.' );
		}
		const htmlBuffer = Buffer.from( request.htmlBase64 || '', 'base64' );
		const html = htmlBuffer.toString( 'utf8' );
		if ( 'full-document' === mode ) {
			await this.navigateWithScriptsDisabled( `data:text/html;charset=utf-8;base64,${ htmlBuffer.toString( 'base64' ) }` );
		} else {
			await this.navigateWithScriptsDisabled( 'data:text/html;charset=utf-8;base64,' );
		}

		let evaluated;
		try {
			await this.cdp.send( 'Emulation.setScriptExecutionDisabled', { value: false }, this.sessionId );
			const expression = `(${ browserRender.toString() })(${ JSON.stringify( { html, mode, context, maxNodes } ) })`;
			evaluated = await this.cdp.send( 'Runtime.evaluate', {
				expression,
				returnByValue: true,
				awaitPromise: false,
				userGesture: false,
			}, this.sessionId );
		} finally {
			await this.cdp.send( 'Emulation.setScriptExecutionDisabled', { value: true }, this.sessionId ).catch( () => {} );
		}
		if ( evaluated.exceptionDetails ) {
			const description = evaluated.exceptionDetails.exception?.description || evaluated.exceptionDetails.text || 'Chrome evaluation failed.';
			const error = new Error( description );
			if ( description.includes( 'DOM node limit exceeded.' ) ) {
				error.failureClass = 'node-limit-exceeded';
			}
			throw error;
		}
		const rendered = evaluated.result?.value;
		if ( ! rendered || 'string' !== typeof rendered.tree ) {
			throw new Error( 'Chrome did not return a canonical tree.' );
		}
		return {
			status: 'ok',
			oracle: this.configuredMetadata(),
			tree: rendered.tree,
			treeBase64: Buffer.from( rendered.tree, 'utf8' ).toString( 'base64' ),
			nodeCount: rendered.nodeCount,
		};
	}

	async close() {
		if ( this.closing ) {
			return;
		}
		this.closing = true;
		await this.renderQueue.catch( () => {} );
		if ( this.cdp ) {
			if ( this.targetId ) {
				await this.cdp.send( 'Target.closeTarget', { targetId: this.targetId }, undefined, 500 ).catch( () => {} );
			}
			await this.cdp.send( 'Browser.close', {}, undefined, 1000 ).catch( () => {} );
		}
		if ( this.chrome ) {
			const chrome = this.chrome;
			await new Promise( ( resolve ) => {
				if ( null !== chrome.exitCode || null !== chrome.signalCode ) {
					resolve();
					return;
				}
				const timers = [];
				const finish = () => {
					for ( const timer of timers ) {
						clearTimeout( timer );
					}
					resolve();
				};
				chrome.once( 'exit', finish );
				timers.push( setTimeout( () => chrome.kill( 'SIGTERM' ), 500 ) );
				timers.push( setTimeout( () => chrome.kill( 'SIGKILL' ), 1000 ) );
				timers.push( setTimeout( finish, 1250 ) );
			} );
		}
		this.websocket?.close();
		if ( this.userDataDirectory ) {
			fs.rmSync( this.userDataDirectory, { recursive: true, force: true } );
		}
		this.chrome = null;
		this.cdp = null;
		this.websocket = null;
		this.targetId = null;
		this.sessionId = null;
		this.userDataDirectory = null;
	}
}

function errorResult( error, oracle, id = undefined ) {
	const message = error.message || String( error );
	const result = {
		status: 'error',
		failureClass: error.failureClass || ( message.includes( 'DOM node limit exceeded.' ) ? 'node-limit-exceeded' : 'oracle-renderer-error' ),
		error: message,
		oracle,
	};
	if ( undefined !== id ) {
		result.id = id;
	}
	return result;
}

function writeResult( stream, result ) {
	if ( false === stream.writable || stream.destroyed ) {
		return;
	}
	try {
		stream.write( `${ JSON.stringify( result ) }\n` );
	} catch ( error ) {
		if ( 'ERR_STREAM_DESTROYED' !== error.code && 'ERR_STREAM_WRITE_AFTER_END' !== error.code ) {
			throw error;
		}
	}
}

async function dispatchRequest( oracle, request ) {
	if ( 'version' === request.command ) {
		await oracle.start();
		return { status: 'ok', oracle: oracle.configuredMetadata() };
	}
	if ( 'shutdown' === request.command ) {
		return { status: 'ok', oracle: oracle.configuredMetadata(), shutdown: true };
	}
	if ( request.command && 'render' !== request.command ) {
		throw new Error( `Unknown command: ${ request.command }` );
	}
	return oracle.render( request );
}

function serveLines( input, output, oracle, shutdown ) {
	const lines = readline.createInterface( { input, crlfDelay: Infinity } );
	let queue = Promise.resolve();
	lines.on( 'line', ( line ) => {
		queue = queue.then( async () => {
			let request;
			try {
				request = JSON.parse( line );
				const result = await dispatchRequest( oracle, request );
				if ( undefined !== request.id ) {
					result.id = request.id;
				}
				writeResult( output, result );
				if ( result.shutdown ) {
					setImmediate( shutdown );
				}
			} catch ( error ) {
				writeResult( output, errorResult( error, oracle.configuredMetadata(), request?.id ) );
			}
		} );
	} );
	return { lines, drained: () => queue };
}

async function runStdioServer( oracle ) {
	let shuttingDown = false;
	let served;
	const shutdown = async () => {
		if ( shuttingDown ) {
			return;
		}
		shuttingDown = true;
		served?.lines.close();
		process.stdin.pause();
		await oracle.close();
	};
	served = serveLines( process.stdin, process.stdout, oracle, shutdown );
	await new Promise( ( resolve ) => served.lines.once( 'close', resolve ) );
	await served.drained();
	await shutdown();
}

async function runSocketServer( oracle, socketPath ) {
	let shutdownPromise = null;
	const connections = new Set();
	const server = net.createServer( ( socket ) => {
		connections.add( socket );
		socket.on( 'error', () => {} );
		socket.once( 'close', () => connections.delete( socket ) );
		serveLines( socket, socket, oracle, shutdown );
	} );
	const shutdown = () => {
		if ( shutdownPromise ) {
			return shutdownPromise;
		}
		shutdownPromise = ( async () => {
			const serverClosed = new Promise( ( resolve ) => server.once( 'close', resolve ) );
			server.close();
			for ( const socket of connections ) {
				socket.end();
			}
			await serverClosed;
			await oracle.close();
			try {
				fs.unlinkSync( socketPath );
			} catch ( error ) {
				if ( 'ENOENT' !== error.code ) {
					throw error;
				}
			}
		} )();
		return shutdownPromise;
	};
	await new Promise( ( resolve, reject ) => {
		server.once( 'error', reject );
		server.listen( socketPath, resolve );
	} );
	process.stdout.write( `${ JSON.stringify( { status: 'ready', socket: socketPath, oracle: oracle.configuredMetadata() } ) }\n` );
	await new Promise( ( resolve ) => server.once( 'close', resolve ) );
	await shutdown();
}

function printUsage() {
	process.stdout.write(
		'Usage: chrome-tree-oracle.js --engine chrome --mode full-document|fragment-body --input PATH [--context TAG] [--max-nodes N] [--chrome-executable PATH]\n' +
		'       chrome-tree-oracle.js --serve [--socket PATH] [--engine chrome] [--chrome-executable PATH]\n' +
		'       chrome-tree-oracle.js --version [--engine chrome] [--chrome-executable PATH]\n'
	);
}

async function main() {
	let options;
	try {
		options = parseArgs( process.argv );
	} catch ( error ) {
		writeResult( process.stdout, errorResult( error, { kind: 'chrome-cdp', engine: 'chrome', available: false } ) );
		process.exitCode = 1;
		return;
	}
	if ( options.help ) {
		printUsage();
		return;
	}
	const oracle = new ChromeOracle( options );
	let stopping = false;
	const stopForSignal = async () => {
		if ( stopping ) {
			return;
		}
		stopping = true;
		await oracle.close().catch( () => {} );
		if ( options.socket ) {
			try {
				fs.unlinkSync( path.resolve( options.socket ) );
			} catch ( error ) {
				if ( 'ENOENT' !== error.code ) {
					process.stderr.write( `${ error.message }\n` );
				}
			}
		}
		process.exit( 0 );
	};
	process.once( 'SIGINT', stopForSignal );
	process.once( 'SIGTERM', stopForSignal );

	if ( options.serve ) {
		if ( options.socket ) {
			await runSocketServer( oracle, path.resolve( options.socket ) );
		} else {
			await runStdioServer( oracle );
		}
		return;
	}
	if ( options.version ) {
		writeResult( process.stdout, { status: 'ok', oracle: oracle.configuredMetadata() } );
		return;
	}
	if ( ! options.input || ! options.mode ) {
		printUsage();
		process.exitCode = 1;
		return;
	}
	try {
		const html = fs.readFileSync( options.input );
		const result = await oracle.render( {
			command: 'render',
			htmlBase64: html.toString( 'base64' ),
			mode: options.mode,
			context: options.context || 'body',
			maxNodes: Number.parseInt( options[ 'max-nodes' ] || 3000, 10 ),
		} );
		writeResult( process.stdout, result );
	} catch ( error ) {
		writeResult( process.stdout, errorResult( error, oracle.configuredMetadata() ) );
		process.exitCode = 2;
	} finally {
		await oracle.close();
	}
}

main().catch( ( error ) => {
	writeResult( process.stdout, errorResult( error, { kind: 'chrome-cdp', engine: 'chrome', available: false } ) );
	process.exitCode = 1;
} );
