# Inventaris Barang Perpustakaan

Plugin SLiMS 9 untuk mencatat inventaris barang per lokasi/ruangan dan mencetak **Kartu Inventaris Ruangan** dalam PDF. Halaman awal menampilkan daftar ruangan; daftar barang baru muncul setelah ruangan dipilih.

## Instalasi

1. Tempatkan plugin di `plugins/inventaris-barang` pada instalasi SLiMS.
2. Pastikan PHP memiliki ekstensi GD, mbstring, dan fileinfo, lalu jalankan dari direktori plugin:

   ```bash
   composer install --no-dev
   ```

3. Pastikan direktori cache SLiMS (`files/cache` pada konfigurasi standar) dapat ditulis oleh proses PHP.
4. Masuk sebagai administrator, buka **System → Plugins**, lalu aktifkan **Inventaris Barang Perpustakaan**. Migrasi plugin membuat tabel `inventory_locations` dan `inventory_items` serta menambahkan referensi ke master lokasi perpustakaan (`mst_location`). Migrasi versi 3 membuat tabel metadata foto `inventory_item_photos`. Migrasi versi 4 memindahkan foto dari penyimpanan BLOB versi sebelumnya ke folder gambar, jika sudah ada.
5. Buka **Stock Take → Inventaris Barang**.

Pada pembaruan instalasi lama, jalankan migrasi plugin melalui **System → Plugins**. Migrasi versi 2 menambahkan referensi master lokasi tanpa menghapus data lama. Hubungkan ruangan yang sudah ada melalui ikon edit pada daftar lokasi. Saat memperbarui ke versi 1.3.0, jalankan semua migrasi hingga versi 4 sebelum menggunakan form barang atau galeri foto. Jika versi 3 dengan kolom BLOB sudah dijalankan, migrasi versi 4 memindahkan foto lama dan menghapus kolom BLOB jika seluruh foto berhasil dipindahkan. Jika data foto lama rusak, data itu dipertahankan untuk pemulihan dan ditampilkan sebagai foto yang tidak dapat dibaca; unggahan baru tetap disimpan ke folder. Unggah ulang sumber foto aslinya dan hapus entri rusak melalui tombol **Hapus Foto**. Cadangkan database sebelum migrasi; rollback otomatis migrasi versi 4 tidak tersedia.

Dependensi PDF dideklarasikan sebagai `mpdf/mpdf: ^8.3.1`; `composer install` memasang versi yang tercatat di `composer.lock`. Folder `vendor` tidak disimpan dalam Git. Jika kode dan dependensi disalin ke image container saat build, perubahan memerlukan rebuild image, lalu recreate container.

Pada versi 1.3.1, jalankan migrasi hingga versi 5 melalui **System → Plugins**. Migrasi ini mengganti indeks unik kode lokasi kartu dengan indeks biasa sehingga beberapa ruangan dapat memakai kode lokasi yang sama, tanpa mengubah data ruangan atau barang. Rollback versi 5 hanya dapat dilakukan jika kode lokasi yang terisi tidak lagi duplikat.

## Memperbarui plugin

Unduh `inventaris-barang-<versi>.zip` dari halaman [Releases](https://github.com/idoalit/slims-inventarisasi-barang-plugin/releases), cadangkan database, lalu ekstrak dan timpa folder `plugins/inventaris-barang`. Paket rilis sudah berisi dependensi PHP (tanpa perlu Composer) dan aset yang sudah dibangun. Buka **System → Plugins** untuk menjalankan migrasi bila ada.

Aplikasi memeriksa rilis terbaru di GitHub dan menampilkan **Versi X tersedia** di kepala aplikasi bagi pengguna dengan hak tulis, lengkap dengan catatan rilis dan tautan unduh. Hasil pemeriksaan disimpan 12 jam di tabel `setting` (`inventory_update_check`); jika GitHub tidak dapat dihubungi, pemeriksaan diulang setelah 1 jam tanpa mengganggu aplikasi. Rilis *pre-release* tidak ditawarkan. Server memerlukan ekstensi PHP cURL dan akses keluar ke `api.github.com`.

## Membuat rilis (pengembang)

Versi hanya tercatat di baris `Version:` pada `inventory.plugin.php`.

```sh
tools/release.sh 2.0.0                 # set versi, build frontend, commit "Release v2.0.0", tag v2.0.0
git push origin main --follow-tags     # GitHub Actions membangun zip dan membuat rilis
```

Workflow `.github/workflows/release.yml` menjalankan `tools/release.sh package` pada setiap tag `v*`: mengambil isi commit tanpa berkas pengembangan (`.gitattributes` export-ignore), membangun frontend, memasang dependensi PHP produksi, membuang font mPDF yang tidak dipakai (paket ±15 MB), menjalankan uji asap `tools/smoke.php` untuk semua jenis PDF dan font, lalu melampirkan zip dan checksum SHA-256 ke rilis dengan catatan rilis otomatis. Tag berakhiran `-rc.1`, `-beta.1`, dan sejenisnya diterbitkan sebagai *pre-release*. Paket juga dapat dibangun lokal dengan `tools/release.sh package` (hasil di `dist/`).

## Penggunaan

### Daftar lokasi/ruangan

- Halaman awal hanya menampilkan lokasi/ruangan beserta jumlah barangnya. Gunakan **Filter Lokasi** untuk menyaring berdasarkan lokasi master SLiMS.
- Klik **Tambah Lokasi** untuk mencatat ruangan dan identitas yang akan tampil pada kartu inventaris.
- Beberapa ruangan dapat menggunakan **Lokasi Perpustakaan** dan **No. Kode Lokasi Kartu** yang sama. Barang tetap dicatat terpisah berdasarkan ruangan.
- Klik **Lihat Barang** pada ruangan untuk membuka daftar barang di dalamnya.
- **Kode ruangan** mengikuti pola `{kode lokasi perpustakaan}-RUANG-{nomor urut 3 digit}`, misalnya `00-RUANG-001`, selaras dengan kode barang `00-INV-000001`. Pada form ruangan, klik **Buat otomatis** untuk mengisi nomor berikutnya dari lokasi perpustakaan yang dipilih. Kode ini dicetak sebagai *No. kode lokasi* pada KIR.

### Barang dalam ruangan

Bagian atas halaman menampilkan ringkasan nama ruangan, kode, lokasi master, dan jumlah barang. Klik **Detail lokasi** untuk membuka informasi wilayah, unit, dan satuan kerja.

- Klik **Tambah Barang** untuk mencatat barang pada ruangan tersebut.
- Gunakan pencarian berdasarkan nama barang, kode, atau merk/model.
- Setelah menyimpan barang, halaman kembali ke daftar barang di ruangan tempat barang disimpan, disertai pesan **Barang berhasil disimpan.** Ini juga berlaku jika barang dipindahkan ke ruangan lain. Setelah menghapus barang, halaman tetap menampilkan ruangan yang sedang dibuka.
- Klik **Kembali ke Daftar Lokasi** untuk memilih ruangan lain.

### Foto barang

Pada form **Tambah Barang** atau **Ubah Barang**, gunakan **Tambah foto** untuk memilih beberapa foto, pratinjau file yang dipilih langsung muncul sebelum dikirim. Klik **Simpan Barang** untuk mengunggahnya. Setelah berhasil, halaman kembali ke daftar barang dengan pesan konfirmasi. Klik nama barang untuk melihat foto yang sudah tersimpan di galeri. Foto bersifat opsional.

- Maksimal **5 foto per barang**, termasuk foto yang sudah tersimpan.
- Setiap unggahan maksimal **2 MB**, dalam format **JPEG, PNG, atau WebP**. Resolusi maksimal 8 megapiksel dan 4096 piksel per sisi.
- Foto lama tampil sebagai pratinjau pada form edit. Klik **Hapus Foto** di bawah foto, lalu konfirmasi untuk langsung menghapus foto tersebut. Tombol tersedia pada form edit dan galeri bagi pengguna dengan hak tulis. Foto lain dan isian form yang belum disimpan tetap dipertahankan.
- Klik nama barang di datagrid untuk membuka galeri. Pengguna dengan hak baca dapat melihat foto; penambahan dan penghapusan memerlukan hak tulis.
- Foto tidak dimasukkan ke PDF Kartu Inventaris Ruangan.

Server memeriksa isi gambar, bukan nama atau MIME yang dikirim browser. Gambar didekode dan dikodekan ulang menjadi JPEG dengan sisi terpanjang maksimal 1280 piksel; berkas asli, metadata, dan nama berkas unggahan tidak disimpan. Berkas JPEG disimpan di `images/inventaris-barang` dengan nama acak 64 karakter dan ekstensi `.jpg`. Database hanya menyimpan ID barang, nama berkas, dan waktu unggah. Nama asli pengguna tidak dipakai sebagai path. Endpoint pembaca tetap memeriksa sesi admin serta hak baca inventaris; path traversal dan symbolic link ditolak. Respons gambar memakai `nosniff` dan `private, no-store`.

Penyimpanan barang dan metadata foto baru memakai satu transaksi database. Penghapusan satu foto memakai transaksi terpisah dengan pemeriksaan hak tulis, CSRF, dan kecocokan ID foto dengan barangnya. Berkas baru dibersihkan bila penyimpanan gagal; penghapusan berkas lama dilakukan setelah commit berhasil. Pemeriksaan kepemilikan foto dan batas jumlah dilakukan dengan mengunci baris barang untuk mencegah unggahan bersamaan melewati batas. Penghapusan barang atau ruangan melalui plugin membersihkan berkas foto setelah transaksi berhasil. Foreign key menghapus metadata foto, tetapi penghapusan langsung melalui SQL tidak membersihkan berkas. Cadangkan database dan folder foto bersama-sama. Kegagalan pembersihan berkas dicatat di log PHP; penghentian proses secara mendadak dapat meninggalkan berkas tanpa metadata.

Folder foto dibuat oleh proses PHP dengan izin direktori `0700` dan berkas `0600`. Proses PHP harus bisa menulis di `images`. Jika memakai container, simpan folder gambar pada volume persisten.

Akses HTTP langsung ke folder foto diblokir oleh `.htaccess` yang dibuat otomatis; Apache harus mengizinkan aturan ini melalui `AllowOverride`. Untuk Nginx yang tidak membaca `.htaccess`, tambahkan aturan penolakan berikut pada konfigurasi server (sesuaikan awalan `/opac` jika instalasi memakai subdirektori), lalu muat ulang konfigurasi:

```nginx
location ^~ /opac/images/inventaris-barang/ {
    deny all;
}
```

Pastikan URL langsung foto menghasilkan 403, sementara pratinjau melalui panel admin tetap tampil. Jangan membuat pengecualian eksekusi PHP untuk folder ini.

Agar lima foto berukuran 2 MB dapat dikirim sekaligus, sesuaikan batas server: `upload_max_filesize` setidaknya `2M`, `post_max_size` setidaknya `12M`, dan `max_file_uploads` setidaknya `5`, beserta batas request pada proxy/web server. Batas aplikasi tetap berlaku meskipun konfigurasi server lebih longgar.

### Mengubah dan menghapus data

Tabel lokasi dan barang menggunakan datagrid bawaan SLiMS, dengan pengurutan kolom dan pagination 20 baris per halaman.

- **Ubah:** klik ikon edit pada baris lokasi atau barang.
- **Hapus:** centang satu atau beberapa baris, lalu gunakan tombol hapus data terpilih. Tombol pilih semua dan batal pilih semua mengikuti kontrol standar SLiMS.
- Penghapusan lokasi juga menghapus seluruh barang di dalamnya. Konfirmasi penghapusan lokasi menyebutkan akibat ini.

Kontrol tambah, edit, dan hapus tersedia bagi pengguna dengan hak tulis modul **Stock Take**. Pengguna dengan hak baca dapat melihat data dan mencetak PDF.

### Cetak PDF

Klik **Cetak PDF** pada daftar lokasi atau halaman ruangan. Dokumen dibuka di tab baru dan memuat barang dari ruangan tersebut.

- Ukuran kertas 330 × 216 mm (lanskap).
- Minimal 13 baris barang; baris kosong ditambahkan jika data kurang dari 13.
- Maksimal 500 barang per ruangan untuk pencetakan. Jika lebih, permintaan ditolak; dokumen tidak dipotong menjadi 500 barang. Batas ini tidak membatasi jumlah data yang dapat ditelusuri melalui pagination.
- Maksimal 10 permintaan PDF per menit per sesi.

## Data yang dicatat

- **Lokasi:** referensi master lokasi perpustakaan, kode kartu, ruangan, provinsi, kabupaten/kota, unit, satuan kerja, kota penandatanganan, serta jabatan, nama, dan identitas penandatangan.
- **Barang:** nama barang, merk/model, nomor seri pabrik, ukuran, bahan, tahun pembuatan/pembelian, kode barang, jumlah/register, harga perolehan, kondisi (B/KB/RB), dan keterangan.

## Integrasi SLiMS

Formulir lokasi dan filter memakai handler AJAX `submitViaAJAX`. Form barang memakai pengiriman multipart `FormData` agar berkas foto ikut terkirim; setelah berhasil, daftar barang di ruangan tujuan dimuat melalui `simbioAJAX` dengan pesan konfirmasi. Jika validasi gagal, pesan tampil pada form agar pengguna dapat memperbaikinya. Edit dan penghapusan melalui datagrid memakai mekanisme standar SLiMS; formulir penghapusan dilengkapi token CSRF plugin dan responsnya memperbarui daftar di panel admin.

Hak akses mengikuti modul `stock_take`. Perubahan data memerlukan hak tulis dan token CSRF. Penghapusan barang melalui daftar ruangan dibatasi ke ruangan yang sedang dibuka. Aktivitas tambah, ubah, hapus, penolakan keamanan, dan pencetakan dicatat melalui system log SLiMS.

PDF diproses melalui `admin/plugin_container.php` dengan aksi `print_pdf`, memerlukan sesi admin aktif, serta mengikuti pemeriksaan IP `smc` dan `smc-stocktake`. Respons PDF memakai kebijakan cache `private, no-store`.

## Pemecahan masalah

- **Kode PHP tampil saat mencetak:** buka ulang menu plugin dan gunakan tombol **Cetak PDF**. Jangan mengakses `plugins/inventaris-barang/print.php` langsung; konfigurasi `plugins/.htaccess` SLiMS menonaktifkan eksekusi PHP langsung pada handler yang terkait.
- **Gagal simpan dengan kolom `filename` tidak ditemukan:** skema foto masih memakai versi BLOB. Jalankan migrasi hingga versi 4; data foto lama yang dapat dibaca dipindahkan ke folder dan data rusak tetap dipertahankan.
- **PDF gagal dibuat:** periksa log PHP, pemasangan dependensi Composer, dan izin tulis direktori cache SLiMS.
- **Error kompatibilitas `setLogger(...): void`:** periksa pustaka mPDF dan PSR Log yang dimuat oleh instalasi utama maupun plugin. Jika error menunjuk ke `lib/psr-log-aware-trait`, kedua trait bawaan SLiMS (`MpdfPsrLogAwareTrait.php` dan `PsrLogAwareTrait.php`) perlu deklarasi `setLogger(LoggerInterface $logger): void` yang kompatibel. Perbaikan pustaka utama ini berada di luar repositori plugin; pastikan ikut terpasang pada server atau image container.

## Pemeriksaan cepat

Jalankan dari direktori plugin di dalam instalasi SLiMS:

```bash
php tests/pdf_template_test.php
php tests/ajax_forms_test.php
php tests/security_controls_test.php
php tests/master_location_integration_test.php
php tests/item_photos_test.php
```

Tes regresi kode lokasi memerlukan PDO MySQL dan koneksi database melalui variabel lingkungan `INVENTORY_TEST_DSN`, `INVENTORY_TEST_USER`, dan `INVENTORY_TEST_PASSWORD`. Jalankan `php tests/shared_location_codes_test.php`. Tes memakai tabel sementara pada koneksinya sendiri, mereproduksi penolakan kode duplikat pada skema lama, lalu memeriksa bahwa migrasi versi 5 mempertahankan data dan mengizinkan ruangan terpisah dengan lokasi serta kode kartu yang sama.

Setelah dependensi Composer tersedia, uji pembuatan PDF:

```bash
php tests/mpdf_runtime_test.php
php tests/render_pdf_sample.php /tmp/inventory-sample.pdf
```

Tes runtime memeriksa bahwa mPDF berasal dari instalasi Composer dan dapat menghasilkan PDF. Tes ini memerlukan ekstensi GD, mbstring, dan fileinfo. Tes foto memeriksa validasi gambar dan aturan perubahan foto dengan pengganti koneksi PDO, tanpa database aktif. Pemeriksaan otomatis tersebut tidak menggantikan uji alur login, edit, hapus, dan cetak melalui browser pada instalasi tujuan.

### Tombol Buat Kode (versi 1.4.0)

Jalankan migrasi plugin **hingga versi 6** melalui **System → Plugins** sebelum memakai form barang versi 1.4.0. Migrasi menambahkan tabel `inventory_item_code_sequences` dan `inventory_item_code_reservations`; data barang lama tidak diubah. Cadangkan kedua tabel bersama database inventaris. Rollback versi 6 tidak tersedia karena penghitung dan riwayat reservasi harus dipertahankan agar nomor tidak digunakan ulang.

Pada form Tambah/Ubah Barang, pilih ruangan lalu klik **Buat Kode** di samping **No. Kode Barang**. Ruangan harus terhubung ke master **Lokasi Perpustakaan** SLiMS. Contoh hasil: `P01-INV-000001`, dengan `P01` berasal dari kode master, bukan No. Kode Lokasi Kartu. Semua ruangan dalam satu perpustakaan memakai urutan bersama; perpustakaan berbeda memiliki urutan terpisah. Nomor tidak direset tahunan dan memiliki minimal enam digit.

Kode hanya dibuat melalui tombol. Kolom tetap dapat diisi manual atau dibiarkan kosong saat menyimpan. Jika sudah terisi, tombol meminta konfirmasi sebelum menggantinya. Memindahkan barang ke ruangan lain tidak mengubah kode; klik tombol kembali jika menginginkan kode baru berdasarkan lokasi tujuan. Satu kode berlaku untuk satu catatan barang, bukan setiap unit pada jumlah/register.

Nomor dipesan secara permanen saat tombol berhasil dan tetap sama saat barang disimpan. Pembatalan form, penggantian kode, atau respons jaringan yang hilang dapat membuat urutan berlubang. Nomor yang sudah dipesan tidak digunakan ulang. Reservasi terikat pada token form dan sesi pengguna; gunakan form yang sama untuk menyimpan kode tersebut. Jika penyimpanan barang/foto gagal, perbaiki data dan coba simpan kembali tanpa memuat ulang form. Jika form atau sesi sudah ditutup, buat nomor baru.

Kode manual baru/yang diubah ditolak jika sudah dipakai barang lain atau dipesan form lain. Kode duplikat lama tetap dapat disimpan tanpa perubahan. Penghitung awal melanjutkan nomor terbesar kode lama yang sesuai pola; alokasi selanjutnya melewati kode yang sudah digunakan. Penguncian database menyelaraskan alokasi dan penyimpanan barang, sedangkan konsumsi reservasi berada dalam transaksi barang/foto.

Tes tambahan:

```sh
node tests/item_codes_form_test.cjs
php tests/item_codes_test.php
```

Tes PHP membutuhkan PDO MySQL serta `INVENTORY_TEST_DSN`, `INVENTORY_TEST_USER`, dan `INVENTORY_TEST_PASSWORD`. Gunakan database pengujian dengan izin membuat/menghapus tabel dan menjalankan subprocess PHP. Tes memakai tabel terisolasi bernama acak `ic_test_*`, membersihkannya setelah selesai, dan menguji konkurensi lewat dua koneksi. Tes tidak membaca atau mengubah data aplikasi.

## Pengawasan & Pemeliharaan (versi 1.5.0)

Jalankan migrasi plugin **hingga versi 7** melalui **System → Plugins**, lalu gunakan menu **Checklist & Jadwal**, **Pemeriksaan**, **Temuan & Tindak Lanjut**, dan **Laporan** di modul stock take. Migrasi menambahkan tabel `inventory_watch_*` tanpa mengubah kondisi atau kode barang. Migrasi versi 6 tetap diperlukan untuk form inventaris. Migrasi versi 7 tidak menyediakan rollback penghapusan karena dokumen pemeriksaan merupakan bukti historis.

### Alur penggunaan

1. **Checklist & Jadwal:** salin template contoh dan sesuaikan butir Sarana, Prasarana, serta Lingkungan Fisik. Isi objek dan petunjuk setiap butir; kosongkan objek untuk menghilangkannya pada versi baru. Maksimal 100 butir per template. Contoh bukan standar penilaian resmi.
2. Klik **Buat jadwal**, pilih ruangan dan template pada langkah pertama, lalu hubungkan tiap butir ke barang di ruangan tersebut atau pilih **Aspek ruangan**. Tentukan penanggung jawab, frekuensi, dan tanggal mulai/akhir. Frekuensi tersedia dari harian hingga tahunan. Jadwal tanggal 31 menggunakan akhir bulan pendek, lalu kembali ke tanggal 31 pada bulan yang memungkinkan.
3. Menu otomatis mengirim POST terlindungi CSRF saat dibuka oleh pengguna dengan hak tulis. Setiap batch membentuk maksimal 50 pemeriksaan yang jatuh tempo, termasuk yang terlewat; batch dilanjutkan sampai selesai. Daftar diperbarui otomatis setelah sinkronisasi selama tidak ada isian yang belum tersimpan. Tanpa cron atau notifikasi eksternal. Pengguna hak baca tidak memicu pembentukan data, tetapi dapat melihat jumlah jadwal yang belum dibentuk.
4. **Pemeriksaan:** isi tanggal pelaksanaan sebenarnya, catatan, dan hasil setiap butir. Hasil selain **Baik** wajib memiliki alasan saat finalisasi. **Perlu tindakan** juga wajib memiliki penanggung jawab, prioritas, dan tenggat. Foto dapat dipilih langsung pada setiap butir. **Simpan foto butir** menyimpan draf terlebih dahulu, kemudian memperbarui foto tanpa memuat ulang dokumen. **Simpan draf** menyimpan isian dan semua foto yang dipilih secara berurutan. Finalisasi dilakukan setelah seluruh penyimpanan berhasil.
5. Finalisasi mengunci checklist dan foto, serta membuat satu temuan per butir yang perlu tindakan. Hasil baik juga disimpan sebagai dokumen. Koreksi berikutnya berupa catatan tambahan; gunakan **Pemeriksaan ulang** untuk kegiatan baru yang terhubung ke dokumen asal. Pemeriksaan insidental membutuhkan alasan dan dilaporkan terpisah dari kegiatan rutin.
6. **Tindak Lanjut:** mulai pekerjaan, catat perbaikan/pemeliharaan, tanggal, dan biaya opsional. Pengguna yang menyimpan tercatat sebagai pelaksana. Pengajuan membutuhkan catatan serta minimal satu foto hasil. **Tanpa pekerjaan** membutuhkan alasan tetapi tidak mewajibkan foto.
7. **Pelapor** memverifikasi: pengguna yang memfinalkan pemeriksaan atau mengirim laporan kerusakan mengisi catatan hasil, lalu menerima atau mengembalikan pekerjaan untuk perbaikan. Jika pelapor dan pelaksana orang yang sama, ia memverifikasi sendiri. Bila akun pelapor sudah dihapus, pengguna dengan hak tulis dapat menggantikannya. Tab **Verifikasi → Perlu saya verifikasi** menampilkan antrean milik pengguna yang sedang masuk. Bukti yang pernah diajukan tidak dapat dihapus; setelah penolakan, pengajuan berikutnya menjadi catatan tindakan baru.
8. **Laporan:** pilih perpustakaan, ruangan, dan periode. Cetak PDF periode atau PDF detail pemeriksaan yang berisi checklist, foto, temuan, dan riwayat verifikasi. Ekspor periode menolak lebih dari 500 pemeriksaan tanpa memotong data. PDF detail dibatasi 500 foto dan memakai thumbnail untuk menjaga penggunaan memori. Persempit periode jika laporan besar.

### Lapor kerusakan

Untuk kerusakan mendadak (misalnya komputer staf mati), gunakan **Tugas → Lapor kerusakan** atau tombol **Laporkan kerusakan** pada detail barang. Satu form mencatat ruangan, barang (atau objek lain non-inventaris), uraian dan foto kerusakan, penangan, prioritas, serta tenggat.

- Laporan masuk ke tab **Tindak lanjut** milik penangan. Penangan dapat menambah **catatan perkembangan** (misalnya "sudah dilaporkan ke unit IT") sebelum mencatat hasil, lalu mengajukan verifikasi ke pelapor.
- Jika penangan adalah pelapor sendiri dan masalah sudah ditangani, aktifkan **Sudah saya tangani sekarang**: pekerjaan dicatat dan laporan langsung selesai dengan verifikasi atas nama pelapor.
- Di belakang layar laporan disimpan sebagai pemeriksaan insidental satu butir bertanda **Perlu tindakan**, sehingga tetap masuk riwayat, laporan periode, dan PDF.

### Label barang dengan QR code

Klik **Cetak label** pada halaman ruangan untuk mencetak label semua barang di ruangan, atau centang barang tertentu lalu klik **Cetak label terpilih**. Label satu barang juga tersedia dari tombol **Label** pada panel detail barang.

- Ukuran: **A4 3×8** (64×34 mm, setara Avery L7159), **A4 2×7** (99×38 mm, setara Avery L7163), atau **stiker 50×30 mm** untuk printer label (satu label per halaman).
- **Mulai dari label ke-**: klik kotak pertama yang masih kosong pada gambar lembar, sehingga lembar yang sudah terpakai sebagian dapat digunakan lagi.
- Label memuat nama institusi, nama barang, kode barang, ruangan, dan lokasi. Barang tanpa kode dicetak dengan ID-nya.
- **Isi QR code** dipilih saat mencetak:
  - **Halaman publik** (bawaan): halaman OPAC `index.php?p=info_barang&i=<id>&t=<token>` yang dapat dibuka siapa pun tanpa login. Halaman memuat nama, kode, kondisi, foto, ruangan, lokasi, merk, tahun, serta status dan riwayat pemeliharaan (tanpa harga dan nama petugas), ditambah tautan pengelolaan untuk petugas. Tautan ditandatangani HMAC dengan kunci yang dibuat otomatis di tabel `setting` (`inventory_label_secret`), sehingga hanya label yang dicetak yang dapat dibuka dan ID barang tidak dapat ditebak. Halaman tidak tercantum di menu OPAC dan dikirim dengan `X-Robots-Tag: noindex`.
  - **Khusus petugas**: tautan `…/admin/plugin_container.php?…&qr=<id barang>` yang membuka detail barang di aplikasi setelah masuk ke SLiMS.
  - Label yang tautannya tidak valid atau barangnya sudah dihapus menampilkan pemberitahuan.
- Cetak PDF dengan skala **100%** (nonaktifkan "sesuaikan ke halaman") agar label tepat pada lembar. Maksimal 500 label per cetak.

Fitur ini memerlukan paket `mpdf/qrcode` (sudah tercantum di `composer.json`). Pada instalasi yang sudah berjalan, jalankan kembali `composer install --no-dev` dari direktori plugin.

### Template kop institusi

Untuk laporan yang harus mengikuti kop resmi institusi, buka **Laporan → Template kop**, lalu unggah PDF kop surat (maksimal 5 MB, hingga 20 template). Halaman 1 PDF menjadi latar halaman pertama laporan; halaman 2 (opsional) menjadi latar halaman berikutnya, sehingga kop lengkap dan kop ringkas dapat dibedakan. Ukuran kertas mengikuti template (A4, F4, tegak atau mendatar).

- Aktifkan **Kop hanya di halaman pertama** agar halaman kedua dan seterusnya dicetak di kertas polos, seperti surat dinas yang lebih dari satu halaman. Opsi ini aktif secara bawaan untuk template satu halaman; untuk template dua halaman, plugin memakai salinan halaman 1 saja.
- Atur **area konten** dengan menarik tepi kotak pada gambar halaman template atau mengisi margin dalam mm. Area atas dan bawah halaman pertama dan halaman berikutnya diatur terpisah; kiri dan kanan berlaku untuk semua halaman.
- Pilih **font isi** dari daftar yang setiap namanya tampil dengan font itu sendiri: Computer Modern, FreeSerif (mirip Times New Roman), DejaVu Serif, FreeSans (mirip Arial/Helvetica), DejaVu Sans, versi *condensed*, FreeMono (mirip Courier New), dan DejaVu Sans Mono. Hanya font yang memiliki huruf tebal dan miring yang ditawarkan. Gambar pratinjau dibuat dengan `php tools/font-previews.php`.
- Pilih **gaya isi** LaTeX atau ISO. Judul dicetak sederhana di tengah beserta nomor dan revisi dokumen; kop dan footer berasal dari template.
- Klik **Coba cetak** untuk melihat laporan bulan berjalan dengan template tersebut. Template tersedia sebagai pilihan **Kop: …** di menu **Cetak PDF** laporan dan dokumen pemeriksaan.
- Berkas disimpan di `images/inventaris-barang/kop` dengan nama acak; data template tersimpan di tabel `setting` (`inventory_pdf_letterheads`).
- PDF 1.5+ yang memakai *object stream* belum dapat dibaca parser FPDI gratis. Simpan ulang sebagai **PDF/A** (di Word: *Simpan sebagai PDF* → *Opsi* → *Sesuai ISO 19005-1*) atau cetak ulang melalui *Microsoft Print to PDF*, lalu unggah kembali. PDF terenkripsi juga ditolak.

### Pratinjau PDF

Semua cetakan PDF (KIR, laporan, dokumen pemeriksaan, dan label) tampil lebih dulu di popup pratinjau SLiMS. Popup memuat penampil PDF milik plugin (`assets/viewer`, berbasis [PDF.js](https://mozilla.github.io/pdf.js/), lisensi Apache-2.0) dengan tombol zoom, **Cetak**, dan **Unduh**. Karena dokumen diambil dan digambar oleh penampil itu sendiri, pengaturan browser yang mengunduh PDF atau membukanya di Adobe Acrobat, serta pengelola unduhan seperti IDM, tidak memengaruhi pratinjau. Tombol **Cetak** mencetak halaman pada sekitar 300 dpi dengan ukuran kertas sesuai dokumen. File PDF.js disalin ke `assets/viewer` oleh `npm run build`.

### Format PDF laporan

Laporan periode dan dokumen pemeriksaan/laporan kerusakan dapat dicetak dalam dua format melalui menu **Cetak PDF**:

- **Gaya LaTeX**: huruf Computer Modern, blok judul di tengah, ringkasan, bagian bernomor, tabel *booktabs* dan gambar bernomor, serta nomor halaman di tengah bawah.
- **Dokumen ISO**: kotak kepala dokumen terkendali di setiap halaman (institusi, judul, nomor dokumen, revisi, tanggal terbit, halaman), bagian bernomor, tabel bergaris penuh, dan tabel pengesahan (nama, jabatan, tanda tangan, tanggal).

Nomor dokumen, revisi, dan tanggal terbit diatur per jenis dokumen melalui **Laporan → Pengaturan dokumen** (memerlukan hak tulis) dan disimpan di tabel `setting` SLiMS (`inventory_pdf_documents`). Format nomor dapat memuat penanda `{id}` (5 digit), `{tahun}`, `{bulan}`, `{romawi}` (bulan dalam angka Romawi), serta `{dari}` dan `{sampai}` untuk laporan periode, misalnya `FRM-SARPRAS-03/{romawi}/{tahun}/{id}`. Tanggal terbit yang dikosongkan memakai tanggal dokumen. Bawaan: `LAP-SARPRAS/{dari}-{sampai}`, `PMR-{id}`, `LK-{id}`, revisi `00`. Huruf **CMU Serif** (Computer Modern Unicode) disertakan di `assets/fonts/cmu` dengan lisensi SIL Open Font License (`OFL.txt`). Kartu Inventaris Ruangan tetap memakai template **Klasik** atau **Modern** yang dipilih dari menu **Cetak KIR**.

### Hari libur

Jadwal mengikuti data hari libur SLiMS (**Sistem → Hari Libur**): libur mingguan (misalnya Sabtu dan Minggu) dan libur bertanggal. Pemeriksaan yang jatuh pada hari libur digeser ke hari kerja berikutnya; untuk jadwal harian, hari libur dilewati. Aturan yang sama dipakai saat membentuk tugas, pada pratinjau tanggal di form jadwal (tanggal yang digeser diberi tanda * beserta alasannya), dan pada proyeksi laporan. Pemeriksaan yang sudah terbentuk tidak dipindahkan bila data libur diubah kemudian, jadi isi libur nasional tahun berjalan sebelum jadwal jatuh tempo.

Petugas jadwal dapat diganti melalui **Ganti petugas** tanpa membuat versi jadwal baru; pemeriksaan yang sudah terbentuk tetapi belum dimulai dapat ikut dialihkan.

### Versi jadwal dan histori

Template yang disalin/direvisi disimpan sebagai versi baru. Jadwal lama tetap memakai checklist dan cakupan yang sudah disetujui. Gunakan **Ganti jadwal** untuk menerapkan versi baru dengan tanggal efektif setelah awal jadwal lama dan tidak di masa lalu. Jadwal lama berakhir sehari sebelum tanggal tersebut. Jadwal tidak dapat diganti/dihentikan pada tanggal yang pemeriksaannya sudah terbentuk; gunakan tanggal berikutnya. Penghentian tidak menghapus pemeriksaan yang sudah ada.

Identitas perpustakaan, ruangan, barang, checklist, penanggung jawab, dan pemeriksa disalin ke dokumen. Perpindahan atau penghapusan barang tidak mengubah bukti. Penghapusan ruangan melalui inventaris menonaktifkan jadwal, mengosongkan referensi ruangan, dan mempertahankan seluruh riwayat pengawasan. Penghapusan ruangan langsung melalui SQL mengosongkan referensi melalui foreign key; sinkronisasi berikutnya menonaktifkan jadwal, dan dashboard langsung mengecualikannya dari pembentukan mendatang.

Kondisi inventaris B/KB/RB tidak otomatis diperbarui dari checklist. Petugas dapat memperbarui kondisi inventaris melalui form barang bila diperlukan.

### Makna indikator

- Filter periode memakai tanggal jadwal untuk pemeriksaan rutin dan tanggal pencatatan untuk insidental. Status temuan adalah status terkini dari pemeriksaan dalam periode itu.
- Rencana rutin mencakup seluruh tanggal dalam periode, termasuk yang belum jatuh tempo. Terlambat berarti jadwal sudah lewat dan pemeriksaan belum final; angka ini juga mencakup jadwal terlewat yang belum dibentuk.
- Cakupan ruangan membandingkan ruangan yang memiliki hasil final diperiksa dengan ruangan dalam jadwal periode. Ruangan aktif tanpa jadwal periode ditampilkan terpisah.
- Cakupan butir memakai pemeriksaan rutin yang terbentuk serta jadwal yang sudah jatuh tempo. Hanya hasil final **Baik/Perlu tindakan** dihitung diperiksa. **Tidak diperiksa**, butir belum terisi, dan draf tetap dalam penyebut; **Tidak berlaku** dikeluarkan dan jumlahnya ditampilkan terpisah.
- Tidak ada konversi otomatis ke nilai a–d. Laporan menyediakan bukti untuk penilai.

### Akses dan penyimpanan bukti

Hak akses mengikuti `stock_take`: baca untuk dashboard, dokumen, foto, dan PDF; tulis untuk seluruh perubahan, termasuk verifikasi. Penugasan tidak membatasi akses per petugas. Endpoint tetap memeriksa sesi admin, cakupan IP, CSRF, kepemilikan foto terhadap dokumen, dan versi data saat menyimpan. Konflik perubahan meminta pengguna memuat ulang; hasil final dan riwayat verifikasi tidak ditimpa.

Foto bukti disimpan terpisah di `images/inventaris-barang/pengawasan`, dengan metadata pada `inventory_watch_photos`. Gunakan aturan penolakan akses HTTP folder `images/inventaris-barang` yang sudah dijelaskan pada bagian foto barang; aturan tersebut juga melindungi subfolder pengawasan. Pembacaan gambar hanya melalui endpoint admin. Maksimal lima foto per hasil/catatan, masing-masing 2 MB; gambar dinormalisasi ke JPEG dengan batas resolusi yang sama seperti foto barang. Pastikan `post_max_size` server cukup untuk seluruh unggahan (misalnya 12 MB untuk lima foto 2 MB).

Cadangkan seluruh tabel `inventory_watch_*` bersama folder bukti. Penyimpanan metadata dan transisi status memakai transaksi/penguncian; berkas baru dibersihkan saat rollback dan berkas draf yang dihapus dibersihkan setelah commit. Seperti galeri barang, penghentian proses mendadak dapat meninggalkan berkas tanpa metadata. PDF dibatasi sepuluh permintaan per menit per sesi dan tidak disimpan pada cache publik.

### Pengujian pengawasan

```sh
php tests/watch_recurrence_test.php
node tests/watch_forms_test.cjs
php tests/watch_integration_test.php
```

Tes integrasi memakai `INVENTORY_TEST_DSN`, `INVENTORY_TEST_USER`, dan `INVENTORY_TEST_PASSWORD`. Gunakan database pengujian dengan izin CREATE/DROP TABLE serta TRIGGER, PHP PDO MySQL, GD, cURL, dan izin subprocess/server HTTP localhost. Seluruh tabel fixture bernama acak `iw_test_*`, tidak membaca data aplikasi, dan dibersihkan setelah pengujian. Tes meliputi konkurensi, alur lengkap, endpoint hak akses/CSRF, unggahan multipart, rollback berkas, histori setelah penghapusan, cakupan, dan HTML laporan. Jika autoloader Composer tersedia, tes juga menghasilkan PDF biner; autoloader pengujian terpisah dapat ditentukan melalui `INVENTORY_TEST_AUTOLOAD`.


## Antarmuka reaktif

Kelima menu menggunakan tampilan bersama di dalam admin SLiMS. **Inventaris Barang** tetap memakai alur ruangan → daftar barang. Identitas, detail inventaris, kondisi, dan foto dipisahkan pada formulir; informasi administratif ruangan dapat dibuka saat dibutuhkan.

**Checklist & Jadwal** dibuka pada tab Jadwal. Tab Template checklist menyediakan editor tambah/hapus butir, maksimal 100 butir. Pembuatan jadwal memiliki tiga langkah: ruangan/checklist, cakupan barang, serta waktu/petugas. Kembali ke langkah sebelumnya mempertahankan isian; mengganti ruangan atau template memuat ulang cakupan dan mengosongkan pemetaan barang yang tidak lagi sesuai.

Filter periode, perpustakaan, ruangan, serta status yang relevan diproses di server sebelum pagination. Tautan pemeriksaan dan temuan mempertahankan konteks daftar untuk tombol kembali. Ringkasan pengawasan berada di **Laporan**; rincian tambahan dapat dibuka tanpa memenuhi tampilan awal.

Pada pemeriksaan, penugasan tampil ketika hasil **Perlu tindakan** dipilih. Foto ditampilkan dan dikelola di butir yang sama. Penyimpanan draf dan foto merupakan transaksi terpisah yang dijalankan berurutan menggunakan versi dokumen dari server. Jika salah satu unggahan gagal, draf dan foto yang sudah berhasil tetap tersimpan; isian serta file yang belum terkirim dipertahankan. Jika respons jaringan hilang, muat ulang untuk memeriksa keadaan dokumen sebelum mengulangi unggahan. Tidak ada autosave berkala.

Alpine.js **3.14.9** disertakan lokal di `assets/` beserta lisensi MIT; instalasi tidak membutuhkan CDN atau build Node. Loader hanya memasang runtime sekali dan komponen mengikuti pergantian fragment AJAX SLiMS. CSS dibatasi pada `.inventory-ui`. Tidak ada perubahan skema database atau tata letak PDF untuk pembaruan antarmuka ini.

Endpoint pengawasan tetap memakai `watch_action`, token CSRF, hak akses, dan respons `ok`, `message`, `url`. Penyimpanan pemeriksaan/foto menambahkan `document` berisi `id`, `version`, `status`, serta metadata `photos` (`id`, `result_id`, dan URL foto melalui controller). Permintaan GET `tab=scope` menyediakan butir template dan barang ruangan untuk wizard. Semua URL memakai ID menu terdaftar; `dashboard` lama dipetakan ke Laporan ketika diterima melalui menu aktif. Bookmark yang memakai hash `supervision.php` lama perlu dibuka kembali dari menu baru.

Tes tambahan UI dan routing:

```sh
php tests/watch_routes_test.php
node tests/watch_forms_test.cjs
php tests/watch_integration_test.php
```

Tes integrasi memerlukan `INVENTORY_TEST_DSN`, `INVENTORY_TEST_USER`, dan `INVENTORY_TEST_PASSWORD`. Tes menggunakan tabel berawalan acak `iw_test_*` dan membersihkannya setelah selesai; jangan menjalankan dengan awalan milik proses tes lain. Untuk pengujian browser fixture, lihat `tests/watch_ui_browser_test.cjs`.

## Impor riwayat pemeriksaan dan pekerjaan

Buka **Tugas → Impor riwayat → Unduh template Excel**. Template dari aplikasi memuat ID ruang beserta lokasi perpustakaan, petugas, dan barang terbaru. Template kosong juga tersedia di `templates/template-riwayat-pemeriksaan.xlsx`.

1. Isi **Pemeriksaan**: satu baris per butir, dengan `nomor_pemeriksaan` yang sama untuk satu kegiatan. `nomor_butir` unik dalam kegiatan. Tanggal, ID ruang, ID pemeriksa, dan nama checklist harus konsisten untuk setiap nomor pemeriksaan. ID barang opsional dan harus berada di ruangan tersebut.
2. Isi **Tindak_lanjut** untuk perbaikan/pemeliharaan yang sudah dilaksanakan. Hubungkan dengan pasangan `nomor_pemeriksaan` dan `nomor_butir` yang memiliki hasil **Perlu tindakan**. Satu baris merangkum pekerjaan pada satu butir. Jika belum ada pekerjaan, biarkan lembar ini kosong.
3. Jika pekerjaan sudah diverifikasi pada masa lalu, isi ketiga kolom `id_verifikator`, `tanggal_verifikasi`, dan `catatan_verifikasi`. Jika kosong, pekerjaan masuk **Menunggu verifikasi**. Temuan tanpa pekerjaan tetap **Terbuka**.
4. Unggah `.xlsx`, klik **Validasi berkas**, periksa pratinjau, lalu **Simpan impor**. Pratinjau berlaku 30 menit dan terikat sesi pengguna. Server memvalidasi ulang saat menyimpan. Semua baris disimpan dalam satu transaksi; kesalahan membatalkan seluruh berkas. Nomor pemeriksaan yang pernah diimpor (tanpa membedakan huruf besar/kecil) ditolak, termasuk unggahan ulang dari sesi berbeda.
5. Hasil terlihat pada **Tugas → Filter → Riwayat**, pilih **Semua tugas** untuk melihat pekerjaan petugas lain. Laporan mengikuti tanggal pemeriksaan asli; ubah periode laporan sesuai data lama.

Tanggal memakai `YYYY-MM-DD` atau tanggal Excel sistem 1900. Biaya berupa angka tanpa pemisah ribuan atau `Rp`, dengan maksimal dua desimal. Batas: 2 MB, 100 pemeriksaan, 500 butir, 100 butir per pemeriksaan, dan 500 tindakan. File contoh pada lembar `Contoh_*` tidak diimpor. Isi lembar data dengan catatan asli, bukan nilai contoh.

Impor tidak membuat jadwal berulang atau template checklist baru. Dokumen diberi jenis **Impor riwayat** sehingga tidak menambah perhitungan cakupan pemeriksaan rutin. Tanggal dan identitas pelaksana asli dipertahankan; waktu pencatatan dan pengimpor tercatat terpisah dalam audit. Foto tidak diimpor; kolom `referensi_bukti` menyimpan rujukan arsip sebagai teks dan tidak mengunduh URL. Jalur impor historis menerima arsip pekerjaan tanpa foto digital; persyaratan foto pada alur pekerjaan biasa tetap berlaku.

Memerlukan PHP `zip` dan `SimpleXML` (tersedia pada server aplikasi), tanpa tambahan paket frontend atau migrasi database baru. Pemeriksaan keamanan memakai hak akses `stock_take`, CSRF, validasi struktur XLSX, batas ukuran hasil dekompresi, dan penolakan rumus/makro pada workbook. Berkas unggahan dibaca dari penyimpanan sementara PHP dan tidak diterbitkan.

Pengujian:

```sh
php tests/history_workbook_test.php
php tests/history_import_test.php
cd frontend
npm run build
```

Tes integrasi membutuhkan `INVENTORY_TEST_DSN`, `INVENTORY_TEST_USER`, `INVENTORY_TEST_PASSWORD`, PDO MySQL, cURL, dan izin membuat tabel/trigger. Tes memakai tabel sementara berawalan acak `ih_test_*`, tidak mengubah tabel aplikasi, dan membersihkan fixture setelah selesai.
