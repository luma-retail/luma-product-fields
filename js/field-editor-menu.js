document.addEventListener('DOMContentLoaded', function () {
    var menu = document.getElementById('menu-posts-product');
    if (menu) {
        menu.classList.add('wp-has-current-submenu', 'wp-menu-open');
    }

    var link = menu ? menu.querySelector('a[href*="page=luma-product-fields"]') : null;
    if (link) {
        link.classList.add('current');
        var li = link.closest('li');
        if (li) {
            li.classList.add('current');
        }
    }
});
