<?php

class Tesseract {
	static $tesseract = "/usr/bin/tesseract";
	static $tessdata = "/home/seeschloss/tessdata";

	static function read($image) {
		$tesseract = self::$tesseract;
		$tessdata = self::$tessdata;

		$unknown_dir = dirname($image)."/unknown";
		@mkdir($unknown_dir);

		$result = `TESSDATA_PREFIX=$tessdata $tesseract "$image" stdout -l bel --psm 10 tsv`;
		$lines = explode("\n", $result);
		$last_line = trim($lines[count($lines) - 2]);

		$fields = explode("\t", $last_line);
		$confidence = $fields[10];
		$char = $fields[11];

		if ($confidence < 50) {
			fwrite(STDERR, "Image $image not readable: $char with confidence $confidence%\n");

			$debug_name = basename($image, ".jpg");
			copy(realpath($image), "{$unknown_dir}/{$debug_name}.jpg");
			file_put_contents("{$unknown_dir}/{$debug_name}.txt", "{$char} {$confidence}%\n".$result);
			return "?";
		}

		switch ($char) {
			case "0":
			case "1":
			case "2":
			case "3":
			case "4":
			case "5":
			case "6":
			case "7":
			case "8":
			case "9":
				return $char;
				break;
			default:
				fwrite(STDERR, "Image $image not readable: $result\n");

				$debug_name = basename($image, ".jpg");
				symlink(realpath($image), "{$unknown_dir}/{$debug_name}.jpg");
				file_put_contents("{$unknown_dir}/{$debug_name}.txt", "?\n".$lines);
				return "?";
		}
	}
}

class Reference {
	static $script = "bash /home/seeschloss/src/compteur/reference/compare.sh";
	static $references_dir = "/home/seeschloss/src/compteur/reference";

	static function compare_files($picture, $reference) {
		$score = `compare -metric AE "{$reference}" -trim -fuzz 40% +repage "{$picture}" "/dev/null" 2>&1`;
	//	$score = `convert "{$picture}" -trim -fuzz 40% +repage "{$reference}" -compose Difference -composite  -colorspace gray -format '%[fx:mean*100]' info:`;

		return (int)$score;
	}

	static function reference_files($reference_id, $number) {
		return glob(self::$references_dir."/".$reference_id."/".$number."*.jpg");
	}

	static function thresholds($previous_value) {
		$thresholds = array();
		
		$number = $previous_value;
		$threshold = 70;
		for ($i = 0; $i < 10; $i++) {
			$thresholds[$number] = $threshold + 25;

			$threshold = $threshold / 3;
			$number = ($number + 1) % 10;
		}

		return $thresholds;
	}

	static function compare($reference_id, $picture, $previous_value = null) {
		for ($number = 0; $number < 10; $number++) {
			$thresholds[$number] = 30;
		}

		if ($previous_value !== null) {
			fwrite(STDERR, "Previous value for {$reference_id} was {$previous_value}.\n");
			$thresholds = self::thresholds($previous_value);
		} else {
			fwrite(STDERR, "Previous value for {$reference_id} was unknown.\n");
			$previous_value = 0;
		}

		$all_numbers = true;
		$number = $previous_value;
		$scores = array();

		$positives = array();

		for ($i = 0; $i < 10; $i++) {
			$reference_files = self::reference_files($reference_id, $number);

			if (count($reference_files) > 0) {
				foreach ($reference_files as $reference_file) {
					$score = self::compare_files($picture, $reference_file);
					$scores[$reference_file] = $score;

					if ($score < $thresholds[$number]) {
						if (!isset($positives[$number]) or $positives[$number] > $score) {
							$positives[$number] = $score;
							fwrite(STDERR, "Digit {$reference_id} is {$number} with score {$score}.\n");
						}
					}
				}
			} else {
				$all_numbers = false;
			}
			$number = ($number + 1) % 10;
		}

		if (count($positives) == 1) {
			$numbers = array_keys($positives);
			fwrite(STDERR, "Digit {$reference_id} is {$numbers[0]}.\n");
			return $numbers[0];
		} else if (count($positives) > 1) {
			asort($positives);
			$numbers = array_keys($positives);
			$lowest = $numbers[0];
			fwrite(STDERR, "Best score for digit {$reference_id} is {$lowest}.\n");
			return $lowest;
		} else {
			fwrite(STDERR, "Image $picture not readable.\n");
		}

		$ignore_5 = false;
		if (($reference_id != "HC-5" and $reference_id != "HP-5") || !$ignore_5) {
			$unknown_dir = dirname($picture)."/unknown";
			@mkdir($unknown_dir);
			$debug_name = basename($picture, ".jpg");
			copy(realpath($picture), "{$unknown_dir}/{$debug_name}.jpg");
			$debug_info = "";
			foreach ($scores as $reference => $score) {
				$number = basename($reference);
				$number = $number[0];

				$debug_info .= "{$reference}\t{$number}\tscore={$score}\tthreshold={$thresholds[$number]}\n";
			}
			file_put_contents("{$unknown_dir}/{$debug_name}.scores.txt", $debug_info);
		}

		if ($all_numbers) {
			return "-";
		} else {
			return "?";
		}
	}

	static function read($image, $digit, $reference_id, $previous_value = null) {
		$script = self::$script;

		$unknown_dir = dirname($image)."/unknown";
		@mkdir($unknown_dir);

		//$result = `$script "$reference_id" "$image" "$previous_value" 2>/dev/null`;
		//$char = $result[0];

		$char = self::compare($reference_id, $image, $previous_value);

		switch ($char) {
			case "0":
			case "1":
			case "2":
			case "3":
			case "4":
			case "5":
			case "6":
			case "7":
			case "8":
			case "9":
				return $char;
				break;
			case "-":
				return "-";
			default:
				return "?";
		}

		return $digit;
	}
}

abstract class Meter {
	public $base_dir = "/home/seeschloss/compteur";

	public $timestamp = 0;
	public $full_image = "";
	public $n_digits = 5;
	public $decimals = 0;

	public $digits = [];
	public $digit_images = [];

	public $previous = "";

	function __construct($timestamp, $full_image) {
		$this->timestamp = $timestamp;
		$this->full_image = $full_image;
		$this->base_dir = dirname($full_image);
	}

	abstract function digit_image($n);

	function read_digit_image($image, $digit) {
		return Tesseract::read($image);
	}

	function digit($n) {
		if (!isset($this->digits[$n])) {
			$image = $this->digit_image($n);
			if ($n == 5) {
				$dash = $this->digit_image("-");
			}
			$this->digits[$n] = $this->read_digit_image($image, $n);
		}
		
		return $this->digits[$n];
	}

	function digits() {
		$digits = "";

		for ($n = 1; $n <= $this->n_digits; $n++) {
			$digit = $this->digit($n);

			$digits .= $digit;
		}

		return $digits;
	}

	function value() {
		if ($this->is_readable()) {
			//$value = $this->digits() / pow(10, $this->decimals);
			if ($this->decimals > 0) {
				$value = substr($this->digits(), 0, -1 * $this->decimals).".".substr($this->digits(), -1 * $this->decimals);
			} else {
				$value = (int)$this->digits();
			}

			if ($this->previous > 0 and $value < $this->previous) {
				fwrite(STDERR, "Meter going backward: was '{$this->previous}' and now is '{$value}'.\n");
			}

			return $value;
		} else {
			return "?";
		}
	}

	function is_readable() {
		//for ($n = $this->n_digits; $n > 0; $n--) {
		for ($n = 1; $n <= $this->n_digits; $n++) {
			$digit = $this->digit($n);

			switch ($digit) {
				case "0":
				case "1":
				case "2":
				case "3":
				case "4":
				case "5":
				case "6":
				case "7":
				case "8":
				case "9":
					break;
				default:
					fwrite(STDERR, "Digit $n not readable: $digit\n");
					return false;
			}
		}

		return true;
	}

	function cleanup() {
		foreach ($this->digit_images as $image) {
			if (file_exists($image)) {
				unlink($image);
			}
		}
	}

	function previous_value($digit) {
		$previous = null;

		$previous_digits = str_replace('.', '', $this->previous);
		if (strlen($previous_digits) == $this->n_digits) {
			$previous = $previous_digits[$digit - 1];
		}

		return $previous;
	}
}

class Meter_HP extends Meter {
	function read_digit_image($image, $digit) {
		$result = Reference::read($image, $digit, "HP-".$digit, $this->previous_value($digit));

		if ($result == "?") {
			return parent::read_digit_image($image, $digit);
		} else {
			return $result;
		}
	}

	function digit_image($n) {
		if (!isset($this->digit_images[$n])) {
			$destination = "{$this->base_dir}/compteur-{$this->timestamp}-HP-{$n}.jpg";

			if (!file_exists($destination)) {
				switch ($n) {
					case "0": // premier chiffre illisible
						$level = "-contrast-stretch 13%x80%";
						$crop = "50x45+2+145";
						$rotate = "0";
						$erode = "-morphology Dilate Diamond";
						break;
					case "1":
						$level = "-contrast-stretch 9%x88%";
						$crop = "50x45+40+150";
						$rotate = "0";
						$erode = "-morphology Dilate Diamond";
						break;
					case "2":
						$level = "-contrast-stretch 9%x88%";
						$crop = "50x45+90+150";
						$rotate = "0";
						$erode = "-morphology Dilate Diamond";
						break;
					case "3":
						$level = "-contrast-stretch 9%x85%";
						$crop = "50x45+135+150";
						$rotate = "0";
						$erode = "-morphology Dilate Diamond";
						break;
					case "4":
						$level = "-contrast-stretch 9%x85%";
						$crop = "50x45+180+150";
						$rotate = "0";
						$erode = "-morphology Dilate Diamond";
						break;
					case "5":
						$level = "-contrast-stretch 12%x85%";
						$crop = "35x45+225+150";
						$rotate = "0";
						//$erode = "-morphology Dilate Diamond";
						$erode = "";
						break;
					case "-":
						$level = "-contrast-stretch 9%x85%";
						$crop = "5x45+270+150";
						$rotate = "0";
						$erode = "";
						break;
				}

				`/usr/bin/convert "{$this->full_image}" -background black -crop {$crop}! -rotate {$rotate} -negate -grayscale Rec709Luminance {$level} -bordercolor White -border 20 {$erode} "{$destination}"`;
			}

			$this->digit_images[$n] = $destination;
		}

		return $this->digit_images[$n];
	}
}

class Meter_HC extends Meter {
	function read_digit_image($image, $digit) {
		$result = Reference::read($image, $digit, "HC-".$digit, $this->previous_value($digit));

		if ($result == "?") {
			return parent::read_digit_image($image, $digit);
		} else {
			return $result;
		}
	}

	function digit_image($n) {
		if (!isset($this->digit_images[$n])) {
			$destination = "{$this->base_dir}/compteur-{$this->timestamp}-HC-{$n}.jpg";

			if (!file_exists($destination)) {
				switch ($n) {
					case "0": // premier chiffre illisible
						$level = "-contrast-stretch 2%x92%";
						$crop = "50x45+0+0";
						$rotate = "0";
						$erode = "-morphology Dilate Diamond";
						break;
					case "1":
						$level = "-contrast-stretch 8%x85%";
						$crop = "50x45+45+10";
						$rotate = "0";
						$erode = "-morphology Dilate Diamond";
						break;
					case "2":
						$level = "-contrast-stretch 14%x84%";
						$crop = "50x45+90+10";
						$rotate = "0";
						$erode = "-morphology Dilate Diamond";
						break;
					case "3":
						$level = "-contrast-stretch 10%x87%";
						$crop = "50x45+135+10";
						$rotate = "5";
						$erode = "-morphology Dilate Diamond";
						break;
					case "4":
						$level = "-contrast-stretch 10%x87%";
						$crop = "50x45+180+10";
						$rotate = "0";
						$erode = "-morphology Dilate Diamond";
						break;
					case "5":
						$level = "-contrast-stretch 12%x75%";
						$crop = "35x45+225+10";
						$rotate = "0";
						$erode = "-morphology Dilate Diamond";
						break;
					case "-":
						$level = "-contrast-stretch 12%x75%";
						$crop = "5x45+270+10";
						$rotate = "0";
						$erode = "";
						break;
				}

				`/usr/bin/convert "{$this->full_image}" -background black -crop {$crop}! -rotate {$rotate} -negate -grayscale Rec709Luminance {$level} -bordercolor White -border 20 {$erode} "{$destination}"`;
			}

			$this->digit_images[$n] = $destination;
		}

		return $this->digit_images[$n];
	}
}

$HP = "?";
$HC = "?";

$compteur_hp = new Meter_HP($argv[2], $argv[1]);
if (!empty($argv[3])) {
	$compteur_hp->previous = $argv[3];
}
if ($compteur_hp->is_readable()) {
	$HP = $compteur_hp->value();
}

$compteur_hc = new Meter_HC($argv[2], $argv[1]);
if (!empty($argv[4])) {
	$compteur_hc->previous = $argv[4];
}
if ($compteur_hc->is_readable()) {
	$HC = $compteur_hc->value();
}

echo "HP: {$HP}\n";
echo "HC: {$HC}\n";

$compteur_hp->cleanup();
$compteur_hc->cleanup();

