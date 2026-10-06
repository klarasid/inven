<?php

use SLiMS\DB;

/**
 * The single bandwidth evidence file of each library location becomes a speed test among its
 * supporting documents: Gedung & Jaringan had two places for the same kind of file. The recap's
 * "Bukti pengukuran diunggah" now looks for a speed test there. Safe to run again.
 */
class MoveBandwidthEvidenceToSupportDocuments extends \SLiMS\Migration\Migration
{
    public function up()
    {
        require_once __DIR__ . '/../src/Sarpras.php';
        \SLiMS\Plugins\Inventory\Sarpras::moveEvidenceToDocuments(DB::getInstance());
    }

    public function down()
    {
        // The files stay supporting documents: nothing told one apart from a speed test uploaded since.
    }
}
