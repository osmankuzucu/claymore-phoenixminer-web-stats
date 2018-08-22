<?php


class json_parser
{

	public $server_list = [];
	public $miner_data = [];
	public $miner_status = [];
	public $miner_data_results = [];
	public $global_hashrate = 0;
	public $miner_count = 0;
	public $wait_timeout = 3;
	public $gpu_temp_yellow = 70;
	public $gpu_temp_red = 75;
	public $gpu_fan_yellow = 50;
	public $gpu_fan_red = 75;


	public function parse_all_json_rpc_calls()
	{

		$this->check_server_availability();

		$this->miner_data_results = (object)[];

		foreach ($this->server_list as $name => $server) {
			$miner_data = (object)[];

			if ($this->miner_status->{$name} == 1) {
				$socket = fsockopen(gethostbyname($server->hostname), $server->port, $err_code, $err_str);

				if ($server->password != null) {
					$append = ',"psw":"' . $server->password . '"';
				} else {
					$append = '';
				}

				$data = '{"id":1,"jsonrpc":"2.0","method":"miner_getstat1"' . $append . '} ' . "\r\n\r\n";

				fputs($socket, $data);
				$buffer = null;
				while (!feof($socket)) {
					$buffer .= fgets($socket, $server->port);
				}
				if ($socket) {
					fclose($socket);
				}

				$response = json_decode($buffer);
				$result = $response->result;

				$miner_info = explode(' - ', $result[0]);
				$miner_data->version = $miner_info[0];
				$miner_data->coin = $miner_info[1];

				$minutes = $result[1];
				$zero = new DateTime('@0');
				$offset = new DateTime('@' . $minutes * 60);
				$diff = $zero->diff($offset);
				$miner_data->uptime = $diff->format('%ad %hh %im');
				$hashrate_stats = explode(';', $result[2]);
				$card_hashrate_stats = explode(';', $result[3]);
				$fan_and_temps = explode(";", $result[6]);
				$miner_data->pool = $result[7];
				$invalid_share_stats = $result[8];


				$miner_data->stats = (object)[
					'hashrate' => round($hashrate_stats[0] / 1000, 2),
					'shares' => $hashrate_stats[1],
					'stale' => $invalid_share_stats[0],
					'rejected' => $hashrate_stats[2]
				];

				$miner_data->card_stats = [];
				foreach ($card_hashrate_stats as $key => $card_hashrate_stat) {
					$val = $key * 2;
					$miner_data->card_stats[] = (object)[
						'hashrate' => round($card_hashrate_stat / 1000, 2),
						'temp' => $fan_and_temps[$val],
						'fan' => $fan_and_temps[$val + 1]
					];
				}

				$temp_sum = 0;
				foreach ($miner_data->card_stats as $card_stat) {
					$temp_sum += $card_stat->temp;
				}
				$miner_data->temp_av = round($temp_sum / sizeof($miner_data->card_stats));
			}

			$this->miner_data_results->{$name} = $miner_data;

		}

		$this->miner_data_results = $this->convert_to_object($this->miner_data_results);

		$this->get_farm_stats();
	}


	public function show_temp_warning($value, $append)
	{

		if ($value >= $this->gpu_temp_red) {
			return "<div class='red-alert' style='display: inline'>$value$append</div>";
		} else if ($value >= $this->gpu_temp_yellow) {
			return "<div class='yellow-alert' style='display: inline'>$value$append</div>";
		} else {
			return $value . $append;
		}

	}

	public function show_fan_warning($value, $append)
	{

		if ($value >= $this->gpu_fan_red) {
			return "<div class='red-alert' style='display: inline'>$value$append</div>";
		} else if ($value >= $this->gpu_fan_yellow) {
			return "<div class='yellow-alert' style='display: inline'>$value$append</div>";
		} else {
			return $value . $append;
		}

	}

	private function check_server_availability()
	{
		$this->server_list = $this->convert_to_object($this->server_list);

		$x = 1;
		foreach ($this->server_list as $name => $server) {
			if ($fp = fsockopen(gethostbyname($server->hostname), $server->port, $err_code, $err_str, $this->wait_timeout)) {
				$this->miner_status[$name] = '1';
			} else {
				$this->miner_status[$name] = '3';
			}
			fclose($fp);
			$x++;
		}

		$this->miner_status = $this->convert_to_object($this->miner_status);

	}


	private function get_farm_stats()
	{
		foreach ($this->miner_data_results as $miner_data_result) {
			$this->global_hashrate += $miner_data_result->stats->hashrate;
			$this->miner_count++;
		}
		$this->global_hashrate = number_format($this->global_hashrate, 2);
	}

	private function convert_to_object($array)
	{
		return json_decode(json_encode($array));
	}

}


?>