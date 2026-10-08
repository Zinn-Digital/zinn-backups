/**
 * Zinn® Backups — admin screen.
 *
 * Plain JavaScript, no build step. Every value from the server is inserted with
 * textContent, never as HTML.
 *
 * @package ZinnDigital\Backups
 */
( function () {
	'use strict';

	const cfg = window.zinnBackups;
	if ( ! cfg ) {
		return;
	}
	const t = cfg.i18n;
	// wp_localize_script turns every scalar into a string; "1677721" + offset would concatenate.
	const chunkSize = parseInt( cfg.chunk, 10 ) || 1048576;
	const $ = ( sel, root ) => ( root || document ).querySelector( sel );
	const $$ = ( sel, root ) => Array.from( ( root || document ).querySelectorAll( sel ) );

	function format( text, ...args ) {
		let i = 0;
		return text.replace( /%(\d+\$)?[sd]/g, ( match, pos ) => {
			const index = pos ? parseInt( pos, 10 ) - 1 : i++;
			return String( args[ index ] );
		} ).replace( /%%/g, '%' );
	}

	function bytes( n ) {
		const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		let v = Number( n ) || 0;
		let u = 0;
		while ( v >= 1024 && u < units.length - 1 ) {
			v /= 1024;
			u++;
		}
		return new Intl.NumberFormat( cfg.locale, { maximumFractionDigits: u ? 1 : 0 } ).format( v ) + ' ' + units[ u ];
	}

	function when( seconds ) {
		try {
			return new Intl.DateTimeFormat( cfg.locale, { dateStyle: 'medium', timeStyle: 'short', timeZone: cfg.timezone || undefined } ).format( new Date( seconds * 1000 ) );
		} catch {
			return new Date( seconds * 1000 ).toLocaleString();
		}
	}

	async function post( action, data ) {
		const body = data instanceof FormData ? data : new FormData();
		if ( ! ( data instanceof FormData ) && data ) {
			Object.keys( data ).forEach( ( key ) => {
				const value = data[ key ];
				if ( Array.isArray( value ) ) {
					value.forEach( ( v ) => body.append( key + '[]', v ) );
				} else if ( value && typeof value === 'object' ) {
					Object.keys( value ).forEach( ( k ) => body.append( key + '[' + k + ']', value[ k ] ) );
				} else {
					body.append( key, value === undefined || value === null ? '' : value );
				}
			} );
		}
		body.append( 'action', 'zinn_backups_' + action );
		body.append( 'nonce', cfg.nonce );
		const response = await fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body } );
		let json;
		try {
			json = await response.json();
		} catch ( cause ) {
			throw new Error( response.status + ' ' + response.statusText, { cause } );
		}
		if ( ! json || ! json.success ) {
			throw new Error( ( json && json.data && json.data.message ) || response.statusText );
		}
		return json.data || {};
	}

	function say( node, text, ok ) {
		if ( ! node ) {
			return;
		}
		node.textContent = text;
		node.classList.toggle( 'zb-ok', !! ok );
		node.classList.toggle( 'zb-error', ! ok );
	}

	// ── The running job ──────────────────────────────────────────────────────

	const panel = $( '#zb-job' );
	let polling = false;

	function renderJob( job ) {
		if ( ! panel || ! job ) {
			return;
		}
		panel.hidden = false;
		const running = job.status === 'running';
		$( '.zb-job__title', panel ).textContent = running ? job.label : ( { done: t.done, failed: t.failed, cancelled: t.cancelled }[ job.status ] || job.label );
		const bar = $( '.zb-job__bar', panel );
		const p = job.progress || {};
		if ( running && p.total > 0 ) {
			bar.value = Math.min( 100, Math.round( ( 100 * p.done ) / p.total ) );
		} else if ( running ) {
			bar.removeAttribute( 'value' );
		} else {
			bar.value = 100;
		}
		const last = ( job.log || [] ).slice( -1 )[ 0 ];
		$( '.zb-job__status', panel ).textContent = job.error || ( last ? last[ 2 ] : t.working );
		const log = $( '.zb-job__log', panel );
		log.textContent = '';
		( job.log || [] ).forEach( ( row ) => {
			const li = document.createElement( 'li' );
			li.className = 'zb-log--' + row[ 1 ];
			li.textContent = when( row[ 0 ] ) + ' — ' + row[ 2 ];
			log.appendChild( li );
		} );
		$( '.zb-job__cancel', panel ).hidden = ! running;
		panel.classList.toggle( 'zb-job--failed', job.status === 'failed' );
		panel.classList.toggle( 'zb-job--done', job.status === 'done' );
		if ( ! running && job.type === 'restore' && job.status === 'done' ) {
			$( '.zb-job__status', panel ).textContent = job.log.slice( -1 )[ 0 ][ 2 ] + ' ' + t.reload;
		}
	}

	async function poll() {
		if ( polling ) {
			return;
		}
		polling = true;
		try {
			for ( ;; ) {
				const data = await post( 'tick' );
				if ( ! data.job ) {
					break;
				}
				renderJob( data.job );
				if ( data.job.status !== 'running' ) {
					loadList( true );
					break;
				}
				await new Promise( ( resolve ) => setTimeout( resolve, data.job.busy ? 3000 : 800 ) );
			}
		} catch ( e ) {
			say( $( '.zb-job__status', panel ), e.message, false );
		} finally {
			polling = false;
		}
	}

	if ( panel ) {
		$( '.zb-job__cancel', panel ).addEventListener( 'click', async () => {
			await post( 'cancel' );
			poll();
		} );
		if ( panel.dataset.running === '1' ) {
			poll();
		}
	}

	// ── Backups tab ─────────────────────────────────────────────────────────

	const list = $( '#zb-list' );
	let backups = [];

	async function loadList( fresh ) {
		if ( ! list ) {
			return;
		}
		list.textContent = t.loading;
		try {
			const data = await post( 'list', { fresh: fresh ? '1' : '0' } );
			backups = data.backups || [];
			renderList();
		} catch ( e ) {
			say( list, e.message, false );
		}
	}

	function badge( text, kind ) {
		const span = document.createElement( 'span' );
		span.className = 'zb-badge zb-badge--' + kind;
		span.textContent = text;
		return span;
	}

	function renderList() {
		list.textContent = '';
		if ( ! backups.length ) {
			list.textContent = t.noBackups;
			return;
		}
		const table = document.createElement( 'table' );
		table.className = 'widefat striped zb-table';
		const tbody = document.createElement( 'tbody' );
		backups.forEach( ( b, index ) => {
			const tr = document.createElement( 'tr' );
			const c1 = document.createElement( 'td' );
			const strong = document.createElement( 'strong' );
			strong.textContent = when( b.created );
			c1.appendChild( strong );
			if ( b.note ) {
				const note = document.createElement( 'div' );
				note.className = 'description';
				note.textContent = b.note;
				c1.appendChild( note );
			}
			const c2 = document.createElement( 'td' );
			c2.appendChild( badge( b.type === 'incremental' ? t.incremental : t.full, b.type ) );
			if ( b.database ) {
				c2.appendChild( badge( t.database, 'plain' ) );
			}
			if ( b.files ) {
				c2.appendChild( badge( t.files, 'plain' ) );
			}
			if ( b.encrypted ) {
				c2.appendChild( badge( t.encrypted, 'lock' ) );
			}
			if ( ! b.this_site ) {
				const other = badge( t.otherSite + ': ' + b.site, 'other' );
				other.dir = 'ltr';
				c2.appendChild( other );
			}
			const c3 = document.createElement( 'td' );
			const size = document.createElement( 'bdi' );
			size.dir = 'ltr';
			size.textContent = bytes( b.size );
			const where = document.createElement( 'bdi' );
			where.textContent = b.label;
			c3.append( size, ' · ', where );
			const c4 = document.createElement( 'td' );
			c4.className = 'zb-row-actions';
			const restore = document.createElement( 'button' );
			restore.type = 'button';
			restore.className = 'button';
			restore.textContent = t.restore;
			restore.addEventListener( 'click', () => openRestore( index ) );
			const del = document.createElement( 'button' );
			del.type = 'button';
			del.className = 'button-link zb-danger';
			del.textContent = t.delete;
			del.addEventListener( 'click', async () => {
				if ( ! window.confirm( t.confirmDelete ) ) {
					return;
				}
				try {
					await post( 'delete', { destination: b.destination, remote: b.remote } );
					loadList( true );
				} catch ( e ) {
					window.alert( e.message );
				}
			} );
			c4.append( restore, ' ' );
			if ( cfg.features && cfg.features.deploy ) {
				const deploy = document.createElement( 'button' );
				deploy.type = 'button';
				deploy.className = 'button';
				deploy.textContent = cfg.features.deployLabel;
				deploy.addEventListener( 'click', async () => {
					try {
						const data = await post( 'pro_deploy_code', { destination: b.destination, remote: b.remote } );
						window.prompt( cfg.features.deployHelp, data.code );
					} catch ( e ) {
						window.alert( e.message );
					}
				} );
				c4.append( deploy, ' ' );
			}
			c4.append( del );
			tr.append( c1, c2, c3, c4 );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		list.appendChild( table );
	}

	const dialog = $( '#zb-restore' );
	let chosen = null;

	function openRestore( index ) {
		chosen = backups[ index ];
		$( '.zb-restore-what', dialog ).textContent = when( chosen.created ) + ' · ' + bytes( chosen.size ) + ' · ' + chosen.label;
		$( '.zb-passphrase', dialog ).hidden = ! chosen.encrypted;
		$( 'form', dialog ).reset();
		dialog.showModal();
	}

	if ( dialog ) {
		dialog.addEventListener( 'close', async () => {
			if ( dialog.returnValue !== 'go' || ! chosen ) {
				return;
			}
			const form = $( 'form', dialog );
			try {
				const data = await post( 'restore', {
					destination: chosen.destination,
					remote: chosen.remote,
					what: form.what.value,
					passphrase: form.passphrase.value,
					confirm: form.confirm.checked ? 'yes' : '',
				} );
				renderJob( data.job );
				poll();
			} catch ( e ) {
				window.alert( e.message );
			}
		} );
	}

	const backupForm = $( '#zb-backup-form' );
	if ( backupForm ) {
		backupForm.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			try {
				const data = await post( 'backup', { what: backupForm.what.value, note: backupForm.note.value } );
				renderJob( data.job );
				poll();
			} catch ( e ) {
				window.alert( e.message );
			}
		} );
	}
	const refresh = $( '.zb-refresh' );
	if ( refresh ) {
		refresh.addEventListener( 'click', () => loadList( true ) );
	}
	if ( list ) {
		loadList( false );
	}

	// ── Storage tab ─────────────────────────────────────────────────────────

	const destForm = $( '#zb-dest-form' );

	function showType() {
		const type = destForm.type.value;
		$$( 'fieldset[data-type]', destForm ).forEach( ( fs ) => {
			fs.hidden = fs.dataset.type !== type;
		} );
		const provider = destForm.provider.value;
		$$( '[data-provider]', destForm ).forEach( ( row ) => {
			row.hidden = row.dataset.provider !== provider;
		} );
	}

	if ( destForm ) {
		destForm.type.addEventListener( 'change', showType );
		destForm.provider.addEventListener( 'change', () => {
			const p = cfg.providers[ destForm.provider.value ];
			if ( p ) {
				destForm.region.value = p.region;
			}
			showType();
		} );
		destForm.region.value = cfg.providers.r2.region;
		showType();
		destForm.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			const fields = {};
			$$( 'input, select', destForm ).forEach( ( el ) => {
				if ( el.name && ! el.closest( 'fieldset[hidden]' ) ) {
					fields[ el.name ] = el.value;
				}
			} );
			fields.type = destForm.type.value;
			try {
				await post( 'save_destination', { destination: fields } );
				say( $( '.zb-form-result', destForm ), t.saved, true );
				window.location.reload();
			} catch ( e ) {
				say( $( '.zb-form-result', destForm ), e.message, false );
			}
		} );
	}

	$$( '.zb-destination' ).forEach( ( row ) => {
		const dest = JSON.parse( row.dataset.destination );
		const result = $( '.zb-dest-result', row );
		$( '.zb-test', row ).addEventListener( 'click', async () => {
			say( result, t.working, true );
			try {
				say( result, ( await post( 'test_destination', { id: dest.id } ) ).message, true );
			} catch ( e ) {
				say( result, e.message, false );
			}
		} );
		$( '.zb-remove', row ).addEventListener( 'click', async () => {
			if ( window.confirm( t.removeDest ) ) {
				await post( 'delete_destination', { id: dest.id } );
				window.location.reload();
			}
		} );
		$( '.zb-edit', row ).addEventListener( 'click', () => {
			const card = $( '#zb-dest-card' );
			card.hidden = false;
			destForm.id.value = dest.id;
			destForm.type.value = dest.type;
			destForm.label.value = dest.label;
			Object.keys( dest.config || {} ).forEach( ( key ) => {
				if ( destForm[ key ] && destForm[ key ].type !== 'checkbox' ) {
					destForm[ key ].value = dest.config[ key ];
				}
			} );
			showType();
			card.scrollIntoView( { behavior: 'smooth' } );
		} );
		const connect = $( '.zb-connect', row );
		if ( connect ) {
			connect.addEventListener( 'click', async () => {
				try {
					const data = await post( 'oauth_start', { id: dest.id } );
					if ( ! data.paste ) {
						window.location.assign( data.url );
						return;
					}
					window.open( data.url, '_blank', 'noopener' );
					const code = window.prompt( t.pasteCode );
					if ( code ) {
						say( result, ( await post( 'oauth_finish', { code } ) ).message, true );
						window.location.reload();
					}
				} catch ( e ) {
					say( result, e.message, false );
				}
			} );
		}
	} );

	// ── Import tab ──────────────────────────────────────────────────────────

	const importForm = $( '#zb-import-form' );
	if ( importForm ) {
		importForm.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			const result = $( '.zb-form-result', importForm );
			try {
				const begin = await post( 'import_begin' );
				const files = Array.from( importForm.files.files || [] );
				for ( let i = 0; i < files.length; i++ ) {
					const file = files[ i ];
					let offset = 0;
					while ( offset < file.size ) {
						const form = new FormData();
						form.append( 'id', begin.id );
						form.append( 'name', file.name );
						form.append( 'index', String( i ) );
						form.append( 'offset', String( offset ) );
						form.append( 'chunk', file.slice( offset, offset + chunkSize ), file.name );
						const res = await post( 'import_chunk', form );
						const have = Number( res.have );
						if ( ! ( have > offset ) ) {
							throw new Error( file.name + ': ' + t.failed );
						}
						offset = have;
						say( result, format( t.uploading, file.name, Math.floor( ( 100 * offset ) / file.size ) ), true );
					}
				}
				const server = $$( 'input[name="server[]"]:checked', importForm ).map( ( el ) => el.value );
				const data = await post( 'import_start', {
					id: begin.id,
					deploy: importForm.deploy.value,
					passphrase: importForm.passphrase.value,
					urls: importForm.urls.value,
					server,
					what: importForm.what.value,
					confirm: importForm.confirm.checked ? 'yes' : '',
				} );
				say( result, '', true );
				renderJob( data.job );
				poll();
			} catch ( e ) {
				say( result, e.message, false );
			}
		} );
	}

	// ── Settings tab ────────────────────────────────────────────────────────

	const settingsForm = $( '#zb-settings-form' );
	if ( settingsForm ) {
		settingsForm.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			const form = new FormData( settingsForm );
			try {
				say( $( '.zb-form-result', settingsForm ), ( await post( 'save_settings', form ) ).message, true );
			} catch ( e ) {
				say( $( '.zb-form-result', settingsForm ), e.message, false );
			}
		} );
	}
}() );
