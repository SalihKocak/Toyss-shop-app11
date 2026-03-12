<?php
$pageTitle = 'Yeni Ürün';
$content = ob_start();
$base = rtrim(parse_url(\ToyShop\Infrastructure\Env::get('APP_URL', ''), PHP_URL_PATH) ?: '', '/') ?: '';
$adminBase = $base . '/admin';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= $adminBase ?>/dashboard">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= $adminBase ?>/products">Ürünler</a></li>
        <li class="breadcrumb-item active">Yeni Ürün</li>
    </ol>
</nav>
<h1 class="section-title">Yeni Ürün</h1>
<p class="admin-lead mb-4">Yeni ürün bilgilerini girin.</p>

<div class="admin-form-card">
    <form id="productForm" enctype="multipart/form-data">
        <div class="mb-3">
            <label class="form-label admin-form-label">Ad</label>
            <input type="text" name="name" class="form-control admin-form-control" required>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label admin-form-label">Marka</label>
                <input type="text" name="brand" class="form-control admin-form-control">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label admin-form-label">Kategori</label>
                <input type="text" name="category" class="form-control admin-form-control">
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label admin-form-label">Fiyat (₺)</label>
                <input type="number" name="price" class="form-control admin-form-control" step="0.01" min="0" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label admin-form-label">Stok</label>
                <input type="number" name="stock" class="form-control admin-form-control" min="0" value="0">
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label admin-form-label">Açıklama</label>
            <textarea name="description" class="form-control admin-form-control" rows="3"></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label admin-form-label">Görseller (jpg, png, webp; max 5MB)</label>
            <input type="file" name="images[]" class="form-control admin-form-control" accept=".jpg,.jpeg,.png,.webp" multiple>
        </div>
        <div class="mb-4">
            <div class="form-check admin-form-check">
                <input type="checkbox" name="isActive" class="form-check-input" value="1" id="isActive" checked>
                <label class="form-check-label" for="isActive">Aktif</label>
            </div>
        </div>
        <button type="submit" class="btn btn-toyshop">Oluştur</button>
    </form>
</div>
<script>
document.getElementById('productForm').addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(this);
    fd.append('isActive', document.getElementById('isActive').checked ? '1' : '0');
    fetch('<?= $adminBase ?>/products/create', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok && d.data.redirect) window.location.href = d.data.redirect;
            else alert(d.error && d.error.message ? d.error.message : 'Oluşturulamadı.');
        })
        .catch(function(){ alert('İstek başarısız.'); });
});
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
