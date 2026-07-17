<div id="settings-modal" class="confirm-overlay" style="display:none">
    <div class="confirm-box">
        <div class="confirm-box-header">Passwort ändern</div>
        <form id="change-password-form">
            <div class="form-field" style="margin-bottom:10px">
                <input class="input-box" type="password" id="cp-current" placeholder="Aktuelles Passwort" required style="width:100%;box-sizing:border-box">
            </div>
            <div class="form-field" style="margin-bottom:10px">
                <input class="input-box" type="password" id="cp-new" placeholder="Neues Passwort" required style="width:100%;box-sizing:border-box">
            </div>
            <div class="form-field" style="margin-bottom:10px">
                <input class="input-box" type="password" id="cp-confirm" placeholder="Neues Passwort bestätigen" required style="width:100%;box-sizing:border-box">
            </div>
            <p id="cp-error" class="form-error" style="display:none"></p>
            <p id="cp-success" class="form-success" style="display:none">Passwort erfolgreich geändert.</p>
            <div class="confirm-actions">
                <button type="button" id="cp-cancel" class="btn">Abbrechen</button>
                <button type="submit" class="btn btn-confirm">Speichern</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('click', function(e) {
    if (e.target.closest('#changePasswordBtn, #changePasswordItem')) {
        document.getElementById('cp-current').value = '';
        document.getElementById('cp-new').value = '';
        document.getElementById('cp-confirm').value = '';
        document.getElementById('cp-error').style.display = 'none';
        document.getElementById('cp-success').style.display = 'none';
        document.getElementById('settings-modal').style.display = '';
    }
});

document.getElementById('cp-cancel').addEventListener('click', function() {
    document.getElementById('settings-modal').style.display = 'none';
});

document.getElementById('settings-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

document.getElementById('change-password-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const errorEl   = document.getElementById('cp-error');
    const successEl = document.getElementById('cp-success');
    errorEl.style.display = 'none';
    successEl.style.display = 'none';

    const currentPassword = document.getElementById('cp-current').value;
    const newPassword     = document.getElementById('cp-new').value;
    const confirm         = document.getElementById('cp-confirm').value;

    if (newPassword !== confirm) {
        errorEl.textContent = 'Die neuen Passwörter stimmen nicht überein.';
        errorEl.style.display = '';
        return;
    }

    const resp = await fetch('/change-password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ currentPassword, newPassword }),
    });
    const data = await resp.json();
    if (!resp.ok) {
        errorEl.textContent = data.error ?? 'Fehler beim Ändern des Passworts.';
        errorEl.style.display = '';
    } else {
        successEl.style.display = '';
        document.getElementById('cp-current').value = '';
        document.getElementById('cp-new').value = '';
        document.getElementById('cp-confirm').value = '';
    }
});
</script>
