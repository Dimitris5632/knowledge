#!/bin/sh
# Azure App Service Startup Command:  sh /home/site/wwwroot/deploy/azure/startup.sh
# Points nginx at public/ so src/, templates/ and migrations/ are not reachable from the internet.
cp /home/site/wwwroot/deploy/azure/nginx.conf /etc/nginx/sites-available/default
service nginx reload
