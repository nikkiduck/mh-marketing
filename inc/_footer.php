<?php
/**
 * _footer.php — site-wide copyright line.
 * Include immediately before </body> on any page that renders HTML.
 *   Pages live at the docroot, this partial lives in inc/:
 *       <?php include __DIR__ . '/inc/_footer.php'; ?>
 */
?>
<footer class="mh-site-footer">
    <p>Copyright &copy; <?php echo date('Y'); ?> Mont Haus LLC. All Rights Reserved.</p>
</footer>
<style>
.mh-site-footer {
    text-align: center;
    padding: 18px 12px;
    margin-top: 24px;
}
.mh-site-footer p {
    font-size: 0.72rem;
    color: #999;
    margin: 0;
    letter-spacing: 0.3px;
}
</style>
