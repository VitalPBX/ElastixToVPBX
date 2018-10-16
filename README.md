# Elastix to VitalPBX
Script and resources to migrate Elastix Installations to VitalPBX

- **[How to Install The Script](#how-to-install-the-script)**
- **[How to Used](#how-to-used)**
- **[What migrates this Script?](#what-migrates-this-script)**
- **[Important Note](#important-note)**

## How to Install The Script
1. If you don't have installed __git__ app, install it in the following way:
<pre>
yum install git -y
</pre>
2. Clone the repository:
<pre>
cd /usr/share
git clone https://github.com/VitalPBX/ElastixToVPBX.git elastix_to_vpbx
</pre>

## How to Used
1. Make a backup of your elastix database
<pre>
mysqldump -uroot -pYOURPASS asterisk > elastix.sql
</pre>
2. Upload the generated sql file to VitalPBX Server
3. Mount your Elastix database on VitalPBX server
<pre>
mysql -uroot -e"create database elastix"
mysql -uroot elastix < elastix.sql
</pre>
4. Execute the migration script
<pre>
php /usr/share/elastix_to_vpbx/migrate.php
</pre>

## What migrates this Script?
Whit this script you can migrate the following data:
- **Extensions**
- **Announcements**
- **Classes of Services**
- **Recordings** (Only the table data, no the files)
- **Call Backs**
- **Custom Destinations (Misc Destinations)**
- **IVRs**
- **Queues**
- **DISA**
- **Trunks**
- **Outbound Routes**
- **Inbound Routes**
- **Ring Groups**
- **Conferences**
- **Time Conditions**
- **Time Groups**
- **Call Groups & Pickup Groups**
## Important Note
This script only migrates the data from Elastix database, so, you will need to upload your recordings after migration.
