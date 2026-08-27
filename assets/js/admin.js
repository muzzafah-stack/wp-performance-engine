/* WP Performance Engine Dashboard JS */

document.addEventListener('DOMContentLoaded', function() {
	// 1. Tab Navigation switching.
	const navItems = document.querySelectorAll('.wppe-nav-item');
	const tabContents = document.querySelectorAll('.wppe-tab-content');

	navItems.forEach(item => {
		item.addEventListener('click', function(e) {
			e.preventDefault();
			const targetTab = this.getAttribute('data-tab');

			navItems.forEach(i => i.classList.remove('active'));
			tabContents.forEach(c => c.classList.remove('active'));

			this.classList.add('active');
			document.getElementById('tab-' + targetTab).classList.add('active');
		});
	});

	// 2. Cloudflare Connection Tester.
	const cfTestBtn = document.getElementById('wppe-cf-test-btn');
	if (cfTestBtn) {
		cfTestBtn.addEventListener('click', function(e) {
			e.preventDefault();
			const btn = this;
			const statusEl = document.getElementById('wppe-cf-status');

			btn.disabled = true;
			statusEl.textContent = 'Testing connection...';
			statusEl.className = 'wppe-badge wppe-badge-info';

			const data = new FormData();
			data.append('action', 'wppe_test_cf_connection');
			data.append('_wpnonce', wppe_ajax.nonce);

			fetch(ajaxurl, {
				method: 'POST',
				body: data
			})
			.then(response => response.json())
			.then(res => {
				btn.disabled = false;
				if (res.success) {
					statusEl.textContent = res.data.message;
					statusEl.className = 'wppe-badge wppe-badge-success';
				} else {
					statusEl.textContent = res.data.message;
					statusEl.className = 'wppe-badge wppe-badge-danger';
				}
			})
			.catch(err => {
				btn.disabled = false;
				statusEl.textContent = 'HTTP Request failed.';
				statusEl.className = 'wppe-badge wppe-badge-danger';
			});
		});
	}

	// 3. Database Cleanup triggers.
	const cleanupButtons = document.querySelectorAll('.wppe-cleanup-btn');
	cleanupButtons.forEach(btn => {
		btn.addEventListener('click', function(e) {
			e.preventDefault();
			const category = this.getAttribute('data-category');
			const aggressive = this.classList.contains('wppe-aggressive');

			if (aggressive && !confirm('WARNING: Aggressive mode will delete all revisions, drafts, trash post entries instantly. Are you sure?')) {
				return;
			}

			const row = this.closest('tr');
			const countEl = row.querySelector('.wppe-item-count');
			const originalButtonText = this.textContent;

			this.disabled = true;
			this.textContent = 'Cleaning...';

			const data = new FormData();
			data.append('action', 'wppe_db_cleanup');
			data.append('category', category);
			data.append('mode', aggressive ? 'aggressive' : 'safe');
			if (aggressive) {
				data.append('confirm_aggressive', '1');
			}
			data.append('_wpnonce', wppe_ajax.nonce);

			fetch(ajaxurl, {
				method: 'POST',
				body: data
			})
			.then(response => response.json())
			.then(res => {
				this.disabled = false;
				this.textContent = originalButtonText;
				if (res.success) {
					countEl.textContent = '0';
					alert(res.data.message);
				} else {
					alert(res.data.message);
				}
			})
			.catch(err => {
				this.disabled = false;
				this.textContent = originalButtonText;
				alert('Connection error occurred.');
			});
		});
	});

	// 4. Log management (Clear & Auto Refresh)
	const clearLogsBtn = document.getElementById('wppe-clear-logs-btn');
	const logTextarea = document.getElementById('wppe-log-textarea');
	const autoRefreshCb = document.getElementById('wppe-log-auto-refresh');

	if (clearLogsBtn && logTextarea) {
		clearLogsBtn.addEventListener('click', function(e) {
			e.preventDefault();
			if (!confirm('Are you sure you want to clear the execution logs?')) {
				return;
			}

			clearLogsBtn.disabled = true;
			clearLogsBtn.textContent = 'Clearing...';

			const data = new FormData();
			data.append('action', 'wppe_clear_logs');
			data.append('_wpnonce', wppe_ajax.nonce);

			fetch(ajaxurl, {
				method: 'POST',
				body: data
			})
			.then(response => response.json())
			.then(res => {
				clearLogsBtn.disabled = false;
				clearLogsBtn.textContent = 'Clear Logs';
				if (res.success) {
					logTextarea.value = 'Log is currently empty.';
					// Also refresh the page to update warnings list if we cleared logs
					window.location.reload();
				} else {
					alert(res.data.message || 'Failed to clear logs.');
				}
			})
			.catch(err => {
				clearLogsBtn.disabled = false;
				clearLogsBtn.textContent = 'Clear Logs';
				alert('Connection error occurred.');
			});
		});
	}

	// Function to fetch logs via AJAX
	function fetchLogs() {
		if (!logTextarea) return;
		
		const data = new FormData();
		data.append('action', 'wppe_get_logs');
		data.append('_wpnonce', wppe_ajax.nonce);

		fetch(ajaxurl, {
			method: 'POST',
			body: data
		})
		.then(response => response.json())
		.then(res => {
			if (res.success) {
				// Save scroll position
				const isAtBottom = logTextarea.scrollHeight - logTextarea.clientHeight <= logTextarea.scrollTop + 20;
				logTextarea.value = res.data.logs;
				if (isAtBottom) {
					logTextarea.scrollTop = logTextarea.scrollHeight;
				}
			}
		})
		.catch(err => {
			console.error('Error fetching logs:', err);
		});
	}

	// Auto refresh log interval
	if (autoRefreshCb && logTextarea) {
		setInterval(function() {
			if (autoRefreshCb.checked && document.getElementById('tab-settings').classList.contains('active')) {
				fetchLogs();
			}
		}, 5000);
	}

	// 5. Plugin Compatibility Repair Menu Action
	const repairButtons = document.querySelectorAll('.wppe-repair-btn');
	repairButtons.forEach(btn => {
		btn.addEventListener('click', function(e) {
			e.preventDefault();
			const plugin = this.getAttribute('data-plugin');
			const originalBtnText = this.innerHTML;

			if (!confirm('Are you sure you want to run the repair action for this plugin? This will perform necessary adjustments and clear the cache.')) {
				return;
			}

			this.disabled = true;
			this.textContent = 'Repairing...';

			const data = new FormData();
			data.append('action', 'wppe_repair_plugin');
			data.append('plugin', plugin);
			data.append('_wpnonce', wppe_ajax.nonce);

			fetch(ajaxurl, {
				method: 'POST',
				body: data
			})
			.then(response => response.json())
			.then(res => {
				this.disabled = false;
				this.innerHTML = originalBtnText;
				if (res.success) {
					alert(res.data.message);
					// Reload page to reflect resolved warnings and reset logs
					window.location.reload();
				} else {
					alert(res.data.message || 'Failed to execute repair.');
				}
			})
			.catch(err => {
				this.disabled = false;
				this.innerHTML = originalBtnText;
				alert('Connection error occurred.');
			});
		});
	});
});
