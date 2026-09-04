import { spawn } from 'node:child_process';

/**
 * Runs a build tool and rejects with its output when it fails.
 *
 * @param {string} command
 * @param {string[]} args
 * @param {{capture?: boolean, cwd?: string, env?: NodeJS.ProcessEnv, label?: string}} [options]
 */
export async function run( command, args, options = {} ) {
	process.stdout.write( `$ ${ [ command, ...args ].join( ' ' ) }\n` );
	return new Promise( ( resolve, reject ) => {
		const child = spawn( command, args, {
			cwd: options.cwd,
			env: { ...process.env, ...options.env },
			stdio: options.capture ? [ 'ignore', 'pipe', 'pipe' ] : 'inherit',
		} );
		let stdout = '';
		let stderr = '';
		// Decoding per chunk would corrupt any character split across a pipe
		// boundary, so the streams decode as a whole.
		child.stdout?.setEncoding( 'utf8' );
		child.stderr?.setEncoding( 'utf8' );
		child.stdout?.on( 'data', ( chunk ) => {
			stdout += chunk;
		} );
		child.stderr?.on( 'data', ( chunk ) => {
			stderr += chunk;
		} );
		child.once( 'error', reject );
		child.once( 'close', ( code, signal ) => {
			if ( code === 0 ) {
				resolve( { stdout } );
				return;
			}
			const detail = `${ stdout }${ stderr }`.trim();
			reject(
				new Error(
					`${ options.label || command } failed${
						signal
							? ` with signal ${ signal }`
							: ` with exit code ${ code }`
					}${ detail ? `\n${ detail }` : '' }`
				)
			);
		} );
	} );
}
