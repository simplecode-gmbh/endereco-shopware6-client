#!/bin/bash

# Define variables
container_name="admin_builder"
host_ssh_port=2222
plugin_host_dir="./"  # Directory on host where your plugin files are stored
plugin_container_dir="/var/www/html/custom/plugins/EnderecoShopware6Client"  # Directory in the container where plugins are stored
temp_host_dir="./temp_plugin_files"

# Start the Dockware container
docker run -d -p $host_ssh_port:22 --name $container_name dockware/dev:6.7.0.1

# Wait for container to fully start up (adjust time as needed)
sleep 10

# Copy plugin files to container and set permissions, excluding certain directories
mkdir -p $temp_host_dir
rsync -az --exclude 'shops/' --exclude '.git/' --exclude 'node_modules/' $plugin_host_dir $temp_host_dir

# Remove existing administration folder content from temp files (will be rebuilt)
rm -rf $temp_host_dir/src/Resources/public/administration/*

# Copy plugin files from temporary directory to container and set permissions
docker cp $temp_host_dir $container_name:$plugin_container_dir
docker exec -u root $container_name chown -R www-data:www-data $plugin_container_dir

# Remove temporary directory
rm -rf $temp_host_dir

# Install and activate the plugin via the console
docker exec $container_name php bin/console plugin:refresh
docker exec $container_name php bin/console plugin:install --activate EnderecoShopware6Client
docker exec $container_name php bin/console cache:clear

# Run build script inside the container
docker exec $container_name bash bin/build-js.sh

# Clean up old build artifacts from host administration folder
rm -rf ${plugin_host_dir}src/Resources/public/administration/*

# Download the entire administration folder with all build artifacts to the host
docker cp $container_name:/var/www/html/custom/plugins/EnderecoShopware6Client/src/Resources/public/administration ${plugin_host_dir}src/Resources/public/

# Change ownership of the entire administration folder to the current user
chown -R $(id -u):$(id -g) ${plugin_host_dir}src/Resources/public/administration

# Stop and remove the container if not needed anymore
docker stop $container_name
docker rm $container_name

# Echo completion message
echo "Plugin admin bundle build complete. JS Bundle downloaded."