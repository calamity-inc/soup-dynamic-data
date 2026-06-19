<?php
$as_string_pool = "";
$as_string_pool_map = [];
$asn_to_pack = [];

function add_as(int $asn, string $handle, string $name)
{
	global $as_string_pool, $as_string_pool_map, $asn_to_pack;

	if ($asn == 4134)
	{
		$name = "China Telecom";
	}

	if (!array_key_exists($handle, $as_string_pool_map))
	{
		$as_string_pool_map[$handle] = strlen($as_string_pool);
		$as_string_pool .= $handle."\0";
	}
	if (!array_key_exists($name, $as_string_pool_map))
	{
		$as_string_pool_map[$name] = strlen($as_string_pool);
		$as_string_pool .= $name."\0";
	}
	$asn_to_pack[$asn] = pack("VVV", $asn, $as_string_pool_map[$handle], $as_string_pool_map[$name]);
}

// Download required data
@mkdir("cache");
if (!file_exists("cache/as.csv"))
{
	echo "Downloading as.csv...\n";
	$data = file_get_contents("https://raw.githubusercontent.com/ipverse/asn-info/master/as.csv");
	$data or die("Download failed");
	file_put_contents("cache/as.csv", $data);
}
if (!file_exists("cache/ip2asn-v4-u32.tsv.gz"))
{
	echo "Downloading ip2asn-v4-u32.tsv.gz...\n";
	$data = file_get_contents("https://iptoasn.com/data/ip2asn-v4-u32.tsv.gz");
	$data or die("Download failed");
	file_put_contents("cache/ip2asn-v4-u32.tsv.gz", $data);
}
if (!file_exists("cache/ip2asn-v6.tsv.gz"))
{
	echo "Downloading ip2asn-v6.tsv.gz...\n";
	$data = file_get_contents("https://iptoasn.com/data/ip2asn-v6.tsv.gz");
	$data or die("Download failed");
	file_put_contents("cache/ip2asn-v6.tsv.gz", $data);
}
if (!file_exists("cache/geolite2-city-ipv4-num.csv"))
{
	echo "Downloading geolite2-city-ipv4-num.csv.gz...\n";
	$data = file_get_contents("https://github.com/sapics/ip-location-db/releases/download/latest/geolite2-city-ipv4-num.csv.gz");
	$data or die("Download failed");
	file_put_contents("cache/geolite2-city-ipv4-num.csv", gzdecode($data));
}
if (!file_exists("cache/geolite2-city-ipv6.csv"))
{
	echo "Downloading geolite2-city-ipv6.csv...\n";
	$data = file_get_contents("https://github.com/sapics/ip-location-db/releases/download/latest/geolite2-city-ipv6.csv.gz");
	$data or die("Download failed");
	file_put_contents("cache/geolite2-city-ipv6.csv", gzdecode($data));
}
$ip2asn_v4_u32_tsv = gzdecode(file_get_contents("cache/ip2asn-v4-u32.tsv.gz"));
$ip2asn_v6_tsv = gzdecode(file_get_contents("cache/ip2asn-v6.tsv.gz"));

// Populate initial AS data from as.csv
echo "Building AS pool (step 1/4)...\n";
$fh = fopen("cache/as.csv", "r");
while ($data = fgetcsv($fh))
{
	if ((int)$data[0])
	{
		add_as((int)$data[0], $data[1], $data[2]);
	}
}
fclose($fh);

// Fill in missing ASNs via ip2asn
echo "Building AS pool (step 2/4)...\n";
foreach (explode("\n", $ip2asn_v4_u32_tsv) as $line)
{
	$arr = explode("\t", $line, 5);
	if (count($arr) == 5 && $arr[2])
	{
		if ($arr[2] && !array_key_exists((int)$arr[2], $asn_to_pack))
		{
			add_as((int)$arr[2], $arr[4], $arr[4]);
			//echo $arr[2]." ".$arr[4]."\n";
		}
	}
}
echo "Building AS pool (step 3/4)...\n";
foreach (explode("\n", $ip2asn_v6_tsv) as $line)
{
	$arr = explode("\t", $line, 5);
	if (count($arr) == 5 && $arr[2])
	{
		if ($arr[2] && !array_key_exists((int)$arr[2], $asn_to_pack))
		{
			add_as((int)$arr[2], $arr[4], $arr[4]);
			//echo $arr[2]." ".$arr[4]."\n";
		}
	}
}

// Finalise AS pool so we can refer to ASNs using offsets
echo "Building AS pool (step 4/4)...\n";
ksort($asn_to_pack);
$as_pool = "";
$asn_to_aso = [];
foreach ($asn_to_pack as $asn => $pack)
{
	$asn_to_aso[$asn] = strlen($as_pool);
	$as_pool .= $pack;
}

// Build IP to AS lookup tables (assuming ip2asn already has them sorted)
echo "Building IPv4 to AS lookup table...\n";
$ipv4_to_aso = "";
foreach (explode("\n", $ip2asn_v4_u32_tsv) as $line)
{
	$arr = explode("\t", $line, 5);
	if (count($arr) == 5 && $arr[2])
	{
		// Little endian so we can do a direct int compare instead of memcmp
		$ipv4_to_aso .= pack("VVV", (int)$arr[0], (int)$arr[1], $asn_to_aso[(int)$arr[2]]);
	}
}
echo "Building IPv6 to AS lookup table...\n";
$ipv6_to_aso = "";
foreach (explode("\n", $ip2asn_v6_tsv) as $line)
{
	$arr = explode("\t", $line, 5);
	if (count($arr) == 5 && $arr[2])
	{
		// inet_pton produces big endian binary format so memcmp will be needed here (not like you have a 128-bit CPU anyway)
		$ipv6_to_aso .= inet_pton($arr[0]).inet_pton($arr[1]).pack("V", $asn_to_aso[(int)$arr[2]]);
	}
}

// Build IP to location lookup tables (assuming ip2loc already has them sorted)
$location_string_pool = "";
$location_string_pool_map = [];
function location_string($str)
{
	global $location_string_pool, $location_string_pool_map;
	if (!array_key_exists($str, $location_string_pool_map))
	{
		$location_string_pool_map[$str] = strlen($location_string_pool);
		$location_string_pool .= $str."\0";
	}
	return $location_string_pool_map[$str];
}
echo "Building IPv4 to location lookup table...\n";
$ipv4_to_location = "";
$fh = fopen("cache/geolite2-city-ipv4-num.csv", "r");
while ($arr = fgetcsv($fh))
{
	(strlen($arr[2]) == 2) or die("Country code must be 2 characters");
	// Little endian so we can do a direct int compare instead of memcmp
	$ipv4_to_location .= pack("VV", (int)$arr[0], (int)$arr[1]).$arr[2]."\0\0".pack("VV", location_string($arr[3]), location_string($arr[5]));
}
fclose($fh);
echo "Building IPv6 to location lookup table...\n";
$ipv6_to_location = "";
$fh = fopen("cache/geolite2-city-ipv6.csv", "r");
while ($arr = fgetcsv($fh))
{
	(strlen($arr[2]) == 2) or die("Country code must be 2 characters");
	// inet_pton produces big endian binary format so memcmp will be needed here (not like you have a 128-bit CPU anyway)
	$ipv6_to_location .= inet_pton($arr[0]).inet_pton($arr[1]).$arr[2]."\0\0".pack("VV", location_string($arr[3]), location_string($arr[5]));
}
fclose($fh);

echo "Compressing & writing output...\n";
file_put_contents("as_pool.bin.gz", gzencode($as_pool, 9));
file_put_contents("as_string_pool.bin.gz", gzencode($as_string_pool, 9));
file_put_contents("ipv4_to_aso.bin.gz", gzencode($ipv4_to_aso, 9));
file_put_contents("ipv6_to_aso.bin.gz", gzencode($ipv6_to_aso, 9));
file_put_contents("location_string_pool.bin.gz", gzencode($location_string_pool, 9));
file_put_contents("ipv4_to_location.bin.gz", gzencode($ipv4_to_location, 9));
file_put_contents("ipv6_to_location.bin.gz", gzencode($ipv6_to_location, 9));
$version = time();
$expiry = (floor(time() / 86400) + 1) * 86400 + 4 * 3600; // Tomorrow at 4:00 UTC
file_put_contents("meta.bin",
	pack(
		"PPVVVVVVVV",
		$version, $expiry,
		strlen($as_pool), strlen($as_string_pool), strlen($ipv4_to_aso), strlen($ipv6_to_aso),
		strlen($location_string_pool), strlen($ipv4_to_location), strlen($ipv6_to_location), 0
	)
	.md5($location_string_pool.$ipv4_to_location.$ipv6_to_location, true) // Location data is not updated daily.
);

if (true)
{
	echo "Writing uncompressed output...\n";
	@mkdir("uncompressed");
	file_put_contents("uncompressed/as_pool.bin", $as_pool);
	file_put_contents("uncompressed/as_string_pool.bin", $as_string_pool);
	file_put_contents("uncompressed/ipv4_to_aso.bin", $ipv4_to_aso);
	file_put_contents("uncompressed/ipv6_to_aso.bin", $ipv6_to_aso);
	file_put_contents("uncompressed/location_string_pool.bin", $location_string_pool);
	file_put_contents("uncompressed/ipv4_to_location.bin", $ipv4_to_location);
	file_put_contents("uncompressed/ipv6_to_location.bin", $ipv6_to_location);
}
