</main>
<footer class="footer">
    <p><?= htmlspecialchars(getConfig(getDB(), 'app_nev', 'Raktárkészlet kezelő')) ?> &copy; <?= date('Y') ?></p>
</footer>
<script>
/* ── Global delete confirmation ────────────────────────────────────────────
   Every element with the class .btn-delete (typically a <a> tag linking to
   a ?torles=ID URL) must be confirmed before the navigation proceeds.
   This prevents accidental deletions triggered by misclicks. */
document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', e => {
        if (!confirm('Biztosan törölni szeretnéd? Ez a művelet nem visszavonható.')) e.preventDefault();
    });
});

/* ── Auto-dismiss flash messages ───────────────────────────────────────────
   Elements with the class .flash-auto (success / info notifications) fade
   out after 3.5 s and are removed from the DOM after the CSS transition
   finishes at 4 s. Error messages should NOT carry this class so the user
   is not rushed while reading validation feedback. */
const flash = document.querySelector('.flash-auto');
if (flash) {
    setTimeout(() => flash.style.opacity = '0', 3500);
    setTimeout(() => flash.remove(), 4000);
}
</script>
</body>
</html>
