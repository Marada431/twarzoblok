    </div><!-- /.admin-content -->
</div><!-- /.admin-wrapper -->

<script>
// Badge z liczbą zgłoszeń – odświeżaj co 60s
function refreshReportsBadge() {
    fetch('<?= BASE_URL ?>/admin/api/stats.php?q=new_reports')
        .then(r => r.json())
        .then(d => {
            const b = document.getElementById('reports-badge');
            if (!b && d.count > 0) return; // badge nie istnieje w DOM jeśli było 0
            if (b) { b.textContent = d.count; b.style.display = d.count > 0 ? 'inline-flex' : 'none'; }
        }).catch(() => {});
}
setInterval(refreshReportsBadge, 60000);

// Modals
document.addEventListener('click', e => {
    const t = e.target;
    if (t.dataset.openModal) document.getElementById(t.dataset.openModal)?.classList.add('open');
    if (t.dataset.closeModal) document.getElementById(t.dataset.closeModal)?.classList.remove('open');
    if (t.classList.contains('modal-overlay')) t.classList.remove('open');
});

// Auto-dismiss alerts po 5s
document.querySelectorAll('.alert').forEach(el => setTimeout(() => el.style.opacity = '0', 4500));
</script>
</body>
</html>
