@extends('settings.layout')

@section('card')
    <h1 id="features" class="list-heading">{{ trans('settings.app_features_security') }}</h1>
    <form action="{{ url("/settings/features") }}" method="POST">
        {!! csrf_field() !!}
        <input type="hidden" name="section" value="features">

        <div class="setting-list">

            {{-- パブリック・アクセス設定をコメントアウト
            <div class="grid half gap-xl">
                <div>
                    <label for="setting-app-public" class="setting-list-label">{{ trans('settings.app_public_access') }}</label>
                    <p class="small">{!! trans('settings.app_public_access_desc') !!}</p>
                    @if(userCan(\BookStack\Permissions\Permission::UsersManage))
                        <p class="small mb-none">
                            <a href="{{ url($guestUser->getEditUrl()) }}">{!! trans('settings.app_public_access_desc_guest') !!}</a>
                        </p>
                    @endif
                </div>
                <div>
                    @include('form.toggle-switch', [
                        'name' => 'setting-app-public',
                        'value' => setting('app-public'),
                        'label' => trans('settings.app_public_access_toggle'),
                    ])
                </div>
            </div>
            --}}

            <div class="grid half gap-xl">
                <div>
                    <label class="setting-list-label">{{ trans('settings.app_secure_images') }}</label>
                    <p class="small">{{ trans('settings.app_secure_images_desc') }}</p>
                </div>
                <div>
                    @include('form.toggle-switch', [
                        'name' => 'setting-app-secure-images',
                        'value' => setting('app-secure-images'),
                        'label' => trans('settings.app_secure_images_toggle'),
                    ])
                </div>
            </div>

            <div class="grid half gap-xl">
                <div>
                    <label class="setting-list-label">{{ trans('settings.app_disable_comments') }}</label>
                    <p class="small">{!! trans('settings.app_disable_comments_desc') !!}</p>
                </div>
                <div>
                    @include('form.toggle-switch', [
                        'name' => 'setting-app-disable-comments',
                        'value' => setting('app-disable-comments'),
                        'label' => trans('settings.app_disable_comments_toggle'),
                    ])
                </div>
            </div>


        </div>

        <div class="form-group text-right">
            <button type="submit" class="button">{{ trans('settings.settings_save') }}</button>
        </div>
    </form>

    {{-- Dropbox Backup Section --}}
    <h1 id="dropbox-backup" class="list-heading mt-xl">Dropboxバックアップ</h1>

    <div class="setting-list">
        {{-- Dropbox Authentication Status --}}
        <div class="card content-wrap">
            <h2 class="list-heading">Dropbox認証状態</h2>
            <div id="dropbox-auth-status" class="mb-m">
                <p class="text-muted">読み込み中...</p>
            </div>
            <div id="dropbox-auth-actions">
                <button id="dropbox-connect-btn" class="button" style="display: none;">
                    Dropboxと連携
                </button>
                <button id="dropbox-test-btn" class="button" style="display: none;">
                    接続テスト
                </button>
                <button id="dropbox-refresh-token-btn" class="button" style="display: none;">
                    トークン更新
                </button>
                <button id="dropbox-revoke-btn" class="button outline" style="display: none;">
                    認証解除
                </button>
            </div>
        </div>

        {{-- Backup Execution --}}
        <div class="card content-wrap mt-m">
            <h2 class="list-heading">バックアップ実行</h2>
            <p class="text-muted small mb-m">
                データベースとファイルをバックアップし、Dropboxにアップロードします。
            </p>
            <div class="mb-m">
                <label class="toggle-switch">
                    <input type="checkbox" id="upload-to-dropbox" checked>
                    <span class="toggle-switch-slider"></span>
                    <span class="toggle-switch-label">Dropboxにアップロード</span>
                </label>
            </div>
            <button id="run-backup-btn" class="button">バックアップ実行</button>
            <div id="backup-progress" style="display: none;" class="mt-m">
                <p class="text-muted">バックアップ実行中...</p>
            </div>
            <div id="backup-result" class="mt-m"></div>
        </div>

        {{-- Backup History --}}
        <div class="card content-wrap mt-m">
            <h2 class="list-heading">バックアップ履歴</h2>
            <button id="refresh-backups-btn" class="button outline mb-m">履歴を更新</button>
            <div id="backup-list">
                <p class="text-muted">バックアップ履歴を読み込むには、Dropbox認証が必要です。</p>
            </div>
        </div>
    </div>

    {{-- JavaScript for Backup Functionality --}}
    <script nonce="{{ $cspNonce }}">
    document.addEventListener('DOMContentLoaded', function() {
        const authStatusDiv = document.getElementById('dropbox-auth-status');
        const connectBtn = document.getElementById('dropbox-connect-btn');
        const testBtn = document.getElementById('dropbox-test-btn');
        const refreshTokenBtn = document.getElementById('dropbox-refresh-token-btn');
        const revokeBtn = document.getElementById('dropbox-revoke-btn');
        const runBackupBtn = document.getElementById('run-backup-btn');
        const refreshBackupsBtn = document.getElementById('refresh-backups-btn');
        const backupProgress = document.getElementById('backup-progress');
        const backupResult = document.getElementById('backup-result');
        const backupList = document.getElementById('backup-list');
        const uploadToDropbox = document.getElementById('upload-to-dropbox');

        // Check authentication status on load
        checkAuthStatus();

        // Connect to Dropbox
        connectBtn.addEventListener('click', function() {
            window.location.href = '/auth/dropbox/redirect';
        });

        // Test connection
        testBtn.addEventListener('click', async function() {
            try {
                const response = await fetch('/api/backup/test', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="token"]')?.getAttribute('content') || ''
                    }
                });
                const data = await response.json();

                if (data.success) {
                    showAlert('success', '接続テスト成功: ' + data.message);
                } else {
                    showAlert('danger', '接続テスト失敗: ' + data.error);
                }
            } catch (error) {
                showAlert('danger', 'エラーが発生しました: ' + error.message);
            }
        });

        // Refresh token
        refreshTokenBtn.addEventListener('click', async function() {
            try {
                const response = await fetch('/api/dropbox/refresh-token', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="token"]')?.getAttribute('content') || ''
                    }
                });
                const data = await response.json();

                if (data.success) {
                    showAlert('success', 'トークンを更新しました');
                    checkAuthStatus();
                } else {
                    showAlert('danger', 'トークン更新失敗: ' + (data.error || data.message));
                }
            } catch (error) {
                showAlert('danger', 'エラーが発生しました: ' + error.message);
            }
        });

        // Revoke authentication
        revokeBtn.addEventListener('click', async function() {
            if (!confirm('Dropbox認証を解除してもよろしいですか？')) {
                return;
            }

            try {
                const response = await fetch('/api/dropbox/revoke-auth', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="token"]')?.getAttribute('content') || ''
                    }
                });
                const data = await response.json();

                if (data.success) {
                    showAlert('success', '認証を解除しました');
                    checkAuthStatus();
                } else {
                    showAlert('danger', '認証解除失敗: ' + data.error);
                }
            } catch (error) {
                showAlert('danger', 'エラーが発生しました: ' + error.message);
            }
        });

        // Run backup
        runBackupBtn.addEventListener('click', async function() {
            if (!confirm('バックアップを実行してもよろしいですか？')) {
                return;
            }

            backupProgress.style.display = 'block';
            backupResult.innerHTML = '';
            runBackupBtn.disabled = true;

            try {
                const response = await fetch('/api/backup/run', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="token"]')?.getAttribute('content') || ''
                    },
                    body: JSON.stringify({
                        upload_to_dropbox: uploadToDropbox.checked
                    })
                });
                const data = await response.json();

                backupProgress.style.display = 'none';
                runBackupBtn.disabled = false;

                if (data.success) {
                    backupResult.innerHTML = '<div class="alert alert-success">' +
                        '<strong>成功:</strong> ' + data.message +
                        '<br>タイムスタンプ: ' + data.timestamp +
                        '</div>';
                    // Refresh backup list
                    if (uploadToDropbox.checked) {
                        setTimeout(loadBackupList, 1000);
                    }
                } else {
                    backupResult.innerHTML = '<div class="alert alert-danger">' +
                        '<strong>失敗:</strong> ' + data.error +
                        '</div>';
                }
            } catch (error) {
                backupProgress.style.display = 'none';
                runBackupBtn.disabled = false;
                backupResult.innerHTML = '<div class="alert alert-danger">' +
                    '<strong>エラー:</strong> ' + error.message +
                    '</div>';
            }
        });

        // Refresh backup list
        refreshBackupsBtn.addEventListener('click', loadBackupList);

        // Check authentication status
        async function checkAuthStatus() {
            try {
                const response = await fetch('/api/dropbox/auth-status');
                const data = await response.json();

                if (data.authenticated) {
                    authStatusDiv.innerHTML = '<div class="alert alert-success">' +
                        '<strong>認証済み</strong><br>' +
                        'アカウント: ' + (data.account_name || 'N/A') +
                        '</div>';
                    connectBtn.style.display = 'none';
                    testBtn.style.display = 'inline-block';
                    refreshTokenBtn.style.display = 'inline-block';
                    revokeBtn.style.display = 'inline-block';
                    loadBackupList();
                } else {
                    authStatusDiv.innerHTML = '<div class="alert alert-warning">' +
                        '<strong>未認証</strong><br>' +
                        'Dropboxと連携してバックアップを開始してください。' +
                        '</div>';
                    connectBtn.style.display = 'inline-block';
                    testBtn.style.display = 'none';
                    refreshTokenBtn.style.display = 'none';
                    revokeBtn.style.display = 'none';
                    backupList.innerHTML = '<p class="text-muted">バックアップ履歴を読み込むには、Dropbox認証が必要です。</p>';
                }
            } catch (error) {
                authStatusDiv.innerHTML = '<div class="alert alert-danger">認証状態の確認に失敗しました</div>';
            }
        }

        // Load backup list
        async function loadBackupList() {
            try {
                const response = await fetch('/api/backup/restorable');
                const data = await response.json();

                if (data.success && data.backups && data.backups.length > 0) {
                    let html = '<div class="table-responsive"><table class="table"><thead><tr>' +
                        '<th>日時</th><th>ファイル数</th><th>サイズ</th><th>操作</th>' +
                        '</tr></thead><tbody>';

                    data.backups.forEach(backup => {
                        const fileCount = backup.files ? backup.files.length : 0;
                        const totalSize = backup.total_size ? (backup.total_size / 1024 / 1024).toFixed(2) + ' MB' : '-';

                        html += '<tr>' +
                            '<td><strong>' + backup.name + '</strong></td>' +
                            '<td>' + fileCount + ' ファイル</td>' +
                            '<td>' + totalSize + '</td>' +
                            '<td>' +
                            '<button class="button outline small backup-download-btn" data-timestamp="' + backup.name + '">ダウンロード</button> ' +
                            '<button class="button small backup-restore-btn" data-timestamp="' + backup.name + '">復元</button>' +
                            '</td>' +
                            '</tr>';
                    });

                    html += '</tbody></table></div>';
                    backupList.innerHTML = html;

                    // Add event delegation for backup buttons
                    backupList.querySelectorAll('.backup-download-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            downloadBackup(this.dataset.timestamp);
                        });
                    });
                    backupList.querySelectorAll('.backup-restore-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            restoreBackup(this.dataset.timestamp);
                        });
                    });
                } else if (data.success) {
                    backupList.innerHTML = '<p class="text-muted">バックアップ履歴がありません。</p>';
                } else {
                    backupList.innerHTML = '<p class="text-danger">エラー: ' + data.error + '</p>';
                }
            } catch (error) {
                backupList.innerHTML = '<p class="text-danger">エラー: ' + error.message + '</p>';
            }
        }

        // Download backup
        async function downloadBackup(timestamp) {
            try {
                showAlert('info', 'バックアップをダウンロード中...');

                const response = await fetch('/api/backup/download', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="token"]')?.getAttribute('content') || ''
                    },
                    body: JSON.stringify({ timestamp: timestamp })
                });

                const data = await response.json();

                if (data.success) {
                    showAlert('success', 'バックアップのダウンロードが完了しました');
                } else {
                    showAlert('danger', 'ダウンロード失敗: ' + data.error);
                }
            } catch (error) {
                showAlert('danger', 'エラー: ' + error.message);
            }
        }

        // Restore backup
        async function restoreBackup(timestamp) {
            // Validation request
            try {
                const validateResponse = await fetch('/api/backup/validate-restore', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="token"]')?.getAttribute('content') || ''
                    },
                    body: JSON.stringify({ timestamp: timestamp })
                });

                const validateData = await validateResponse.json();

                if (!validateData.success) {
                    showAlert('danger', '検証失敗: ' + validateData.error);
                    return;
                }

                // Confirmation dialog
                const confirmMessage =
                    '【警告】データベースを復元します\n\n' +
                    '復元するバックアップ: ' + timestamp + '\n' +
                    validateData.warning + '\n\n' +
                    '本当に実行しますか？';

                if (!confirm(confirmMessage)) {
                    return;
                }

                const createBackup = confirm('復元前に現在のデータベースをバックアップしますか？\n（強く推奨）');

                // Execute restore
                showAlert('info', 'データベースを復元中... しばらくお待ちください');

                const restoreResponse = await fetch('/api/backup/restore', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="token"]')?.getAttribute('content') || ''
                    },
                    body: JSON.stringify({
                        timestamp: timestamp,
                        create_backup_first: createBackup
                    })
                });

                const restoreData = await restoreResponse.json();

                if (restoreData.success) {
                    showAlert('success', '✅ データベースの復元が完了しました！ページをリロードしてください。');
                    setTimeout(() => {
                        if (confirm('復元が完了しました。ページをリロードしますか？')) {
                            window.location.reload();
                        }
                    }, 2000);
                } else {
                    showAlert('danger', '❌ 復元失敗: ' + restoreData.error);
                }

            } catch (error) {
                showAlert('danger', 'エラー: ' + error.message);
            }
        }

        // Make functions globally available
        window.downloadBackup = downloadBackup;
        window.restoreBackup = restoreBackup;

        // Show alert message
        function showAlert(type, message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-' + type;
            alertDiv.textContent = message;
            authStatusDiv.parentElement.insertBefore(alertDiv, authStatusDiv.nextSibling);
            setTimeout(() => alertDiv.remove(), 5000);
        }
    });
    </script>
@endsection