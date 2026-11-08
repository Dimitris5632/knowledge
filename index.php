<?php
// Fallback: if the web server's root is this folder instead of public/, send visitors to public/.
header('Location: public/', true, 302);
