# Prevent directory listing
Options -Indexes

# Protect sensitive files
<FilesMatch "^(config|auth|\.env)">
    Order allow,deny
    Deny from all
</FilesMatch>

# Rewrite rule for clean URLs
RewriteEngine On

# Redirect to login if not authenticated (except login page)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_URI} !^/login\.php
RewriteCond %{REQUEST_URI} !^/auth/
RewriteRule ^(.*)$ index.php?url=$1 [QSA,L]