<footer class="site-footer">
    <div class="footer-icons" aria-hidden="true">&#128666; &nbsp; &#128230; &nbsp; &#128722;</div>
    <p><strong>ErrandGo</strong> &middot; <?= e(ERRANDGO_TAGLINE) ?></p>
    <p>&copy; <?= date('Y') ?> ErrandGo &middot; PEIS005 Data Warehousing &middot; Mandaluyong College of Science and Technology</p>
</footer>
<button type="button" id="to-top" class="to-top" aria-label="Back to top" title="Back to top">&uarr;</button>
<script>
(function () {
    var b = document.getElementById('to-top');
    if (!b) { return; }
    window.addEventListener('scroll', function () { b.classList.toggle('show', window.scrollY > 300); });
    b.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
})();
</script>
