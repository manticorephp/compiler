<?php
// N-body (CLBG) — float-heavy, tight loops, numeric precision.
// Fixed step count; prints energy before/after to %.9f for stable parity.

$PI = 3.141592653589793;
$SOLAR_MASS = 4.0 * $PI * $PI;
$DAYS = 365.24;

// bodies: [x,y,z, vx,vy,vz, mass]
$b = [
    [0.0,0.0,0.0, 0.0,0.0,0.0, $SOLAR_MASS],
    [4.84143144246472090e+00,-1.16032004402742839e+00,-1.03622044471123109e-01,
     1.66007664274403694e-03*$DAYS,7.69901118419740425e-03*$DAYS,-6.90460016972063023e-05*$DAYS,
     9.54791938424326609e-04*$SOLAR_MASS],
    [8.34336671824457987e+00,4.12479856412430479e+00,-4.03523417114321381e-01,
     -2.76742510726862411e-03*$DAYS,4.99852801234917238e-03*$DAYS,2.30417297573763929e-05*$DAYS,
     2.85885980666130812e-04*$SOLAR_MASS],
    [1.28943695621391310e+01,-1.51111514016986312e+01,-2.23307578892655734e-01,
     2.96460137564761618e-03*$DAYS,2.37847173959480950e-03*$DAYS,-2.96589568540237556e-05*$DAYS,
     4.36624404335156298e-05*$SOLAR_MASS],
    [1.53796971148509165e+01,-2.59193146099879641e+01,1.79258772950371181e-01,
     2.68067772490389322e-03*$DAYS,1.62824170038242295e-03*$DAYS,-9.51592254519715870e-05*$DAYS,
     1.65103335312815010e-05*$SOLAR_MASS],
];

$n = count($b);

// offset momentum of the sun
$px = 0.0; $py = 0.0; $pz = 0.0;
for ($i = 0; $i < $n; $i++) {
    $px += $b[$i][3] * $b[$i][6];
    $py += $b[$i][4] * $b[$i][6];
    $pz += $b[$i][5] * $b[$i][6];
}
$b[0][3] = -$px / $SOLAR_MASS;
$b[0][4] = -$py / $SOLAR_MASS;
$b[0][5] = -$pz / $SOLAR_MASS;

function energy(array $b, int $n): float {
    $e = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $e += 0.5 * $b[$i][6] * ($b[$i][3]*$b[$i][3] + $b[$i][4]*$b[$i][4] + $b[$i][5]*$b[$i][5]);
        for ($j = $i + 1; $j < $n; $j++) {
            $dx = $b[$i][0] - $b[$j][0];
            $dy = $b[$i][1] - $b[$j][1];
            $dz = $b[$i][2] - $b[$j][2];
            $d = sqrt($dx*$dx + $dy*$dy + $dz*$dz);
            $e -= ($b[$i][6] * $b[$j][6]) / $d;
        }
    }
    return $e;
}

printf("%.9f\n", energy($b, $n));

$dt = 0.01;
$steps = 50000;
for ($s = 0; $s < $steps; $s++) {
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $dx = $b[$i][0] - $b[$j][0];
            $dy = $b[$i][1] - $b[$j][1];
            $dz = $b[$i][2] - $b[$j][2];
            $d2 = $dx*$dx + $dy*$dy + $dz*$dz;
            $mag = $dt / ($d2 * sqrt($d2));
            $bi6 = $b[$i][6]; $bj6 = $b[$j][6];
            $b[$i][3] -= $dx * $bj6 * $mag;
            $b[$i][4] -= $dy * $bj6 * $mag;
            $b[$i][5] -= $dz * $bj6 * $mag;
            $b[$j][3] += $dx * $bi6 * $mag;
            $b[$j][4] += $dy * $bi6 * $mag;
            $b[$j][5] += $dz * $bi6 * $mag;
        }
    }
    for ($i = 0; $i < $n; $i++) {
        $b[$i][0] += $dt * $b[$i][3];
        $b[$i][1] += $dt * $b[$i][4];
        $b[$i][2] += $dt * $b[$i][5];
    }
}

printf("%.9f\n", energy($b, $n));
