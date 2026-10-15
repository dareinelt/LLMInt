<?php

/**
 * admin/partials/layout_foot.php
 *
 * Closes the page shell that layout_head.php opened and renders the shared
 * description popup that the "?" buttons of the page open.
 */

require_once __DIR__ . '/help.php';

?>
</main>

</div><!-- /.page-body -->

<?php admin_help_overlay(); ?>

</body>
</html>
