<?php
// Pure presentation routines extracted from mdawiki-site/site/funcoes.php.
// SQL mutations and filesystem dependencies were removed; lookups are injected.
namespace MDAWiki\Wiki;
function ident($v) { return \MDAWiki\Database::id((string)$v); }
function remove_acentos($v) { return str_replace('_',' ',ident(str_replace(' ','_',(string)$v))); }
function str_to_url($v,$encode=true) { return str_replace(' ','_',trim((string)$v)); }
function url_to_str($v) { return str_replace('_',' ',(string)$v); }
function nvl($v,$default='') { return $v ?? $default; }
function mb_convert_encoding($v,$to,$from) { return $v; } // Input is normalized UTF-8.
function resolve_image($name) { return ($GLOBALS['mdawiki_image_resolver'])($name); }

function page_tabelas($conteudo)
{
	preg_match_all("/{\|(.*?)\|}/s", $conteudo, $tab);
	foreach ($tab[1] as $num => $valor) {
		$template = $tab[0][$num];
		preg_match_all("/{{(.*?)}}/s", $valor, $arr1);
		preg_match_all("/\[\[(.*?)\]\]/s", $valor, $arr2);
		$arr = array_merge($arr1[0], $arr2[0]);
		foreach ($arr as $rep) {
			$valor = str_replace($rep, str_replace("|", "++", $rep), $valor);
		}
		$arr_tb = explode("|", $valor);
		//$r = "<div style='overflow:auto'><table ".$arr_tb[0]."><tr>";
		$r = "<br><div class='row' style='overflow:auto'><table class='table'><thead><tr class='primary'>";
		$thead = true;
		$tinicio = true;
		foreach ($arr_tb as $k => $tabl) {
			if ($k <= 0) {
				continue;
			}
			$tag = "";
			if (substr($tabl, 0, 1) == "!") {
				if (strpos(substr($tabl, 1, strlen($tabl)), "!") !== false) {
					$tag = substr($tabl, 1, strpos(substr($tabl, 1, strlen($tabl)), "!"));
					$tabl = substr($tabl, strlen($tag) + 2, strlen($tabl));
				}
			}
			$tabl = str_replace("++", "|", $tabl);
			if (trim($tabl) == "-") {
				if ($tinicio) {
					$tinicio = false;
					continue;
				} elseif ($thead) {
					$r .= "</tr></thead><tbody><tr $tag>";
					$thead = false;
				} else {
					$r .= "</tr><tr $tag>";
				}
			} else {
				if ($thead) {
					$r .= "<th $tag>" . $tabl . "</th>";
				} else {
					$r .= "<td $tag>" . $tabl . "</td>";
				}
			}
		}
		if ($thead) {
			$r .= "</th></thead>";
		} else {
			$r .= "</tr></tbody>";
		}
		$r .= "</table></div>";
		//echo $r;
		$conteudo = str_replace($template, $r, $conteudo);
	}
	return $conteudo;
}
/** Parses image template parameters for rendering and dependency resolution. */
function image_template_options(string $valor): array
{
	preg_match_all("/\[\[(.*?)\]\]/is", $valor, $valor_tmpl);
	foreach ($valor_tmpl[1] as $chave_v => $valor_v) {
		$valor_r = str_replace("|", " &vert; ", $valor_v);
		$valor = str_replace($valor_v, $valor_r, $valor);
	}
	$tmp_p = explode("|", $valor);
	$vls = array();
	for ($i = 0; $i <= count($tmp_p) - 1; $i++) {
		list($key, $value) = explode("=", $tmp_p[$i] . "=");
		if ($value == "") {
			$value = $key;
			$key = "url";
		}
		$key = ident($key);
		$vls[$key] = $value;
	}
	return $vls;
}

function page_templates($conteudo, $page)
{
	global $conn;

	$ident = ident($page);

	//TEMPLATES----------------------------------------------------------------
	
	preg_match_all("/{{(.*?)}}/s", $conteudo, $tmp);
	foreach ($tmp[1] as $key => $value) {
		$value = str_replace("'", "\'", $value);
		$value2 = str_replace("'", "\'", $tmp[0][$key]);
		
	}

	//REDIRECIONAMENTOS----------------------------------------------------------------
	
	preg_match_all("/{{{Redirecionar:(.*?)}}}/is", $conteudo, $tmp);
	foreach ($tmp[1] as $key => $value) {
		$template = $tmp[0][$key];
		$value = str_replace("'", "\'", $value);
		
	}


	//CATEGORIAS----------------------------------------------------------------
	
	$conteudo = str_ireplace("{{Categorias:", "{{Categoria:", $conteudo);
	preg_match_all("/{{Categoria:(.*?)}}/is", $conteudo, $cate);
	$cat = array();
	foreach ($cate[1] as $valor) {
		$cat = array_merge($cat, explode(",", $valor));
	}
	$cat = array_map('trim', $cat);
	$cat = array_unique($cat);
	foreach ($cat as $key => $value) {
		$value = trim($value);
		if ($value == "") {
			continue;
		}

		
	}
	$conteudo = preg_replace('~{{Categoria:(.+?)}}\r\n~', '', $conteudo);
	$conteudo = preg_replace('~{{Categoria:(.+?)}}~', '', $conteudo);


	//ESPECIALIDADE------------------------------------------------------------
	preg_match_all("/{{Especialidade:(.*?)}}/is", $conteudo, $tmpl);
	foreach ($tmpl[1] as $chave => $valor) {
		$template = $tmpl[0][$chave];
		$tmp_p = explode("|", $valor);
		$vls = array();
		for ($i = 0; $i <= count($tmp_p) - 1; $i++) {
			list($key, $value) = explode("=", $tmp_p[$i] . "=");
			$vls[strtolower(trim($key))] = trim($value);
		}
		$vls["clube"] = (!isset($vls["clube"]) ? "DBV" : $vls["clube"]);
		if ($vls["clube"] == "DBV") {
			$cor = (@$vls["cores"] != "" ? $vls["cores"] : $vls["area"]);
			$cor_bg_a = "";
			$cor_txt_a = "";
			$cor_bg_b = "";
			switch ($cor) {
				case "HM":
					$cor_bg_a = "#1E90FF";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#00BFFF";
					break;
				case "CS":
					$cor_bg_a = "#391868";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#6C3F92";
					break;
				case "EN":
					$cor_bg_a = "#DCDCDC";
					$cor_txt_a = "#000";
					$cor_bg_b = "#F8F8FF";
					break;
				case "AR":
					$cor_bg_a = "#003300";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#006600";
					break;
				case "AP":
					$cor_bg_a = "#B80000";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#FF0000";
					break;
				case "HD":
					$cor_bg_a = "#D4652E";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#F17F1F";
					break;
				case "AM":
					$cor_bg_a = "#101F5B";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#25408F";
					break;
				case "AM-EB":
					$cor_bg_a = "#468572";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#6EAF9C";
					break;
				case "AA":
					$cor_bg_a = "#3C2415";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#754C29";
					break;
				case "AD":
					$cor_bg_a = "#0e0e36";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#565885";
					break;
			}

			$tmp = "<style>";
			$tmp .= ".templ_especialidade_dbv{border-spacing: 1;border-collapse: collapse;text-align:center;color:#FFF;font-size:14px;}";
			$tmp .= ".templ_especialidade_dbv td{border:1px solid #FFF;}";
			$tmp .= "</style>";
			$tmp .= "<no_text><div style='overflow:auto'><table width=100% class='templ_especialidade_dbv'>";
			$tmp .= "<tr style=\"background:$cor_bg_a;color:$cor_txt_a;font-weight:bold;\">";
			$tmp .= "<td rowspan=2 width=50 style='background:$cor_bg_b;padding-left:5px;padding-right:5px;'><a border=0 href='{{{url_site}}}imagem@Emblema_D1'><img width=95 src='imagem@Emblema_D1!tmpl!img'></a></td>";
			$tmp .= "<td>&Aacute;rea</td><td>C&oacute;digo</td>";
			$tmp .= (@$vls["area"] != "ME" ? "<td>N&iacute;vel</td>" : "");
			$tmp .= "<td>Ano</td><td>Institui&ccedil;&atilde;o de Origem</td>";
			$tmp .= "<td rowspan=2 width=50 style='padding-left:5px;padding-right:5px;'><a border=0 href='{{{url_site}}}imagem@" . str_to_url(@$vls["icone"]) . "'><img width=" . (@$vls["area"] == "ME" ? 115 : 100) . " src='imagem@" . @$vls["icone"] . "!img'></a></td>";
			$tmp .= "</tr>";
			$tmp .= "<tr style=\"background:$cor_bg_b;color:$cor_txt_a;\">";
			$tmp .= "<td>" . @$vls["area"] . "</td>";
			$tmp .= "<td>" . @$vls["cod"] . "</td>";
			$tmp .= (@$vls["area"] != "ME" ? "<td>" . @$vls["nivel"] . "</td>" : "");
			$tmp .= "<td>" . @$vls["ano"] . "</td>";
			$tmp .= "<td>" . @$vls["origem"] . "</td>";
			$tmp .= "</tr></table></div>";
			$tmp .= "<template class='mda-code'>" . $vls["area"] . "-" . @$vls["cod"] . "</template>";
			$tmp .= "</no_text>";
			$conteudo = str_replace($template, $tmp, $conteudo);
		} elseif ($vls["clube"] == "AVT") {
			$cor = (@$vls["cores"] != "" ? $vls["cores"] : $vls["area"]);
			$cor_bg_a = "";
			$cor_txt_a = "";
			$cor_bg_b = "";
			$area = "";
			switch ($cor) {
				case "AM":
					$cor_bg_a = "#0050b2";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#2889FF";
					$area = "Artes Manuais";
					break;
				case "AE":
					$cor_bg_a = "#EDEDED";
					$cor_txt_a = "#000";
					$cor_bg_b = "#F8F8FF";
					$area = "Atividades Espirituais";
					break;
				case "AR":
					$cor_bg_a = "#B80000";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#FF0000";
					$area = "Atividades Recreativas";
					break;
				case "EN":
					$cor_bg_a = "#003300";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#006600";
					$area = "Estudos da Natureza";
					break;
				case "HD":
					$cor_bg_a = "#FEA500";
					$cor_txt_a = "#FFF";
					$cor_bg_b = "#FFB324";
					$area = "Habilidades Dom&eacute;sticas";
					break;
			}
			$tmp = "<style>";
			$tmp .= ".templ_especialidade_avt{border-spacing: 1;border-collapse: collapse;text-align:center;color:#FFF;font-size:14px;}";
			$tmp .= ".templ_especialidade_avt td{border:1px solid #FFF;}";
			$tmp .= "</style>";
			$tmp .= "<no_text><div style='overflow:auto'><table width=100% class='templ_especialidade_avt'>";
			$tmp .= "<tr style=\"background:$cor_bg_a;color:$cor_txt_a;font-weight:bold;\">";
			$tmp .= "<td rowspan=2 width=50 style='background:$cor_bg_b;padding-left:5px;padding-right:5px;'><a border=0 href='{{{url_site}}}imagem@Emblema_A1'><img width=95 src='imagem@Emblema_A1!tmpl!img'></a></td>";
			$tmp .= "<td>&Aacute;rea</td><td>C&oacute;digo</td>";
			$tmp .= "<td rowspan=2 width=50 style='padding-left:5px;padding-right:5px;'><a border=0 href='{{{url_site}}}imagem@" . str_to_url(@$vls["icone"]) . "'><img width=" . (@$vls["area"] == "ME" ? 115 : 100) . " src='imagem@" . @$vls["icone"] . "!img'></a></td>";
			$tmp .= "</tr>";
			$tmp .= "<tr style=\"background:$cor_bg_b;color:$cor_txt_a;\">";
			$tmp .= "<td>" . @$vls["area"] . " - $area</td>";
			$tmp .= "<td>" . @$vls["cod"] . "</td>";
			$tmp .= "</tr></table></div>";
			$tmp .= "<template class='mda-code'>" . $vls["area"] . "-" . @$vls["cod"] . "</template>";
			$tmp .= "</no_text>";
			$conteudo = str_replace($template, $tmp, $conteudo);
		}
	}

	//ESPECIALIDADE OBSOLETA------------------------------------------------------------
	preg_match_all("/{{Especialidade Obsoleta(.*?)}}/is", $conteudo, $tmpl);
	foreach ($tmpl[1] as $chave => $valor) {
		$template = $tmpl[0][$chave];
		$tmp_p = explode("|", $valor);
		$vls = array();
		for ($i = 0; $i <= count($tmp_p) - 1; $i++) {
			list($key, $value) = explode("=", $tmp_p[$i] . "=");
			$key = str_replace(":", "", $key);
			$vls[strtolower(trim($key))] = trim($value);
		}
		if ($page == "@") {
			@$vls["icone"] = remove_acentos(@$vls["icone"]);
		}

		$tmp = "<table border=\"0\" cellpadding=\"1\" cellspacing=\"1\" style=\"width: 100%;border:0\">"
			. "<tr><td " . (@$vls["icone"] <> "" ? "colspan=2" : "") . " style=\"padding:5px;text-align:center;background:#A90101;color:#FFF;font-weight:bold;\">Especialidade Descontinuada</td></tr>"
			. "<tr class='backg2'>"
			. (@$vls["icone"] <> "" ? "<td><img src='imagem@" . str_to_url(@$vls["icone"]) . "!img' width=100></td>" : "")
			. "<td>"
			. "<i><b>Nota:</b> Esta especialidade n&atilde;o faz mais parte do manual de especialidades da [[Divisao_Sul_Americana|Divis&atilde;o Sul-Americana]], pois foi revisada como obsoleta, ou foi substitu&iacute;da, ou n&atilde;o est&aacute; dentro da realidade dos desbravadores de nossa divis&atilde;o.</i>"
			. (@$vls["termino"] <> "" ? "<br><br><div style='text-align:right;'><i>Especialidade descontinuada em " . @$vls["termino"] . "</i></div>" : "")
			. "</td>"
			. "</tr>"
			. "</table>";
		if ($page != "@") {
			$tmp = mb_convert_encoding($tmp, 'ISO-8859-1', 'UTF-8');
		}
		$conteudo = str_replace($template, $tmp, $conteudo);
	}

	//MAIS LINKS------------------------------------------------------------
	preg_match_all("/{{Mais Links:(.*?)}}/is", $conteudo, $tmpl);
	foreach ($tmpl[1] as $chave => $valor) {
		$template = $tmpl[0][$chave];
		preg_match_all("/\[\[(.*?)\]\]/s", $valor, $links);
		foreach ($links[1] as $key => $value) {
			list($link_p, $link_t) = explode("|", $value . "|");
			$link_t = ($link_t == "" ? $link_p : $link_t);
			$valor = str_replace($links[0][$key], "[[$link_p++$link_t]]", $valor);
		}

		$tmp_p = explode("|", $valor);
		$vls = array();
		for ($i = 0; $i <= count($tmp_p) - 1; $i++) {
			list($key, $value) = explode("=", $tmp_p[$i] . "=");
			$key = remove_acentos($key);
			$key = remove_acentos(mb_convert_encoding($key, 'UTF-8', 'ISO-8859-1'));
			$key = strtolower(trim($key));

			if (substr($key, 0, 5) == "link ") {
				$key = "links";
			} elseif (substr($key, 0, 10) == "descricao ") {
				$vls["links"] .= "|" . trim($value);
			}
			if (@$vls[$key] != "") {
				$vls[$key] .= "," . trim($value);
			} else {
				$vls[$key] = trim($value);
			}
		}
		$tmp = "";

		if (@$vls["desambiguacao"] != '' || @$vls["links"] != '') {
			$tmp .= "<no_text><div class='maislinks'>";
			if (@$vls["desambiguacao"] != "") {
				$v = @$vls["desambiguacao"];
				$v = str_replace("++", "|", $v);
				if (substr($v, 0, 2) != "[[") {
					$v = "[[$v]]";
				}
				$tmp .= "'''Nota:''' Para outros significados, veja $v.<br>";
			}
			if (@$vls["links"] != "") {
				$ve = explode(",", $vls["links"] ?? "");
				foreach ($ve as $v) {
					$v = trim(str_replace("++", "|", $v));
					if (substr($v, 0, 2) != "[[") {
						$v = "[[$v]]";
					}
					$tmp .= "'''Nota:''' " . (@$vls["texto"] <> "" ? $vls["texto"] : "Veja tamb&eacute;m,") . " $v.<br>";
				}
			}
			$tmp .= "</div></no_text>";
		}
		$conteudo = str_replace($template, $tmp, $conteudo);
	}

	//DESAMBIGUAÇÃO------------------------------------------------------------
	//echo $conteudo;
	preg_match_all("/{{Desambiguacao:(.*?)}}/is", $conteudo, $tmpl);
	foreach ($tmpl[1] as $chave => $valor) {
		$template = $tmpl[0][$chave];
		preg_match_all("/\[\[(.*?)\]\]/s", $valor, $links);
		foreach ($links[1] as $key => $value) {
			list($link_p, $link_t) = explode("|", $value . "|");
			$link_t = ($link_t == "" ? $link_p : $link_t);
			$valor = trim(str_replace($links[0][$key], "[[$link_p++$link_t]]", $valor));
		}

		$tmp_p = explode("|", $valor);
		$vls = array();
		for ($i = 0; $i <= count($tmp_p) - 1; $i++) {
			list($key, $value) = explode("=", $tmp_p[$i] . "=");
			$key = remove_acentos($key);
			$key = remove_acentos(mb_convert_encoding($key, 'UTF-8', 'ISO-8859-1'));
			$key = strtolower(trim($key));

			if (substr($key, 0, 5) == "link ") {
				$key = "links";
			} elseif (substr($key, 0, 10) == mb_convert_encoding("descricao ", 'ISO-8859-1', 'UTF-8')) {
				$vls["links"] .= "|" . trim($value);
			}
			if (@$vls[$key] != "") {
				$vls[$key] .= "," . trim($value);
			} else {
				$vls[$key] = trim($value);
			}
		}
		$tmp = "<div class='desambiguacao' style='border-top:1px solid #CCC;border-bottom:1px solid #CCC;color:#555;padding:7px 0 7px 0;margin-bottom:10px;'><i>"
			. "<img src='{{{url_site}}}@imgs/desambig.png' style='float:left;margin:5px 5px 0 0;'>"
			. "Esta &eacute; uma p&aacute;gina de desambigua&ccedil;&atilde;o, a qual lista artigos associados a um mesmo t&iacute;tulo."
			. "<br><span style='font-size:11px;'>Verifique se o que voc&ecirc; procura est&aacute; listado abaixo.</span>"
			. "</i></div>";
		if (@$vls[mb_convert_encoding("termo", 'ISO-8859-1', 'UTF-8')] != '' || @$vls["links"] != '') {
			if (@$vls[mb_convert_encoding("termo", 'ISO-8859-1', 'UTF-8')] != "") {
				$v = @$vls[mb_convert_encoding("termo", 'ISO-8859-1', 'UTF-8')];
				$tmp .= "<b>$v</b> pode se referir &agrave;:";
			}
			$tmp .= "<ul>";
			$ve = explode(",", $vls["links"] ?? "");
			foreach ($ve as $v) {
				$v = trim(str_replace("++", "|", $v));
				if (substr($v, 0, 2) != "[[") {
					$v = "[[$v]]";
				}
				$tmp .= "<li>$v.</li>";
			}
			$tmp .= "</ul>";
		}
		$conteudo = str_replace($template, $tmp, $conteudo);
	}


	//BIOGRAFIA------------------------------------------------------------
	preg_match_all("/{{Biografia:(.*?)}}/is", $conteudo, $tmpl);
	foreach ($tmpl[1] as $chave => $valor) {
		$template = $tmpl[0][$chave];

		$tmp_p = explode("|", $valor);
		$vls = array();
		for ($i = 0; $i <= count($tmp_p) - 1; $i++) {
			list($key, $value) = explode("=", $tmp_p[$i] . "=");
			$key = remove_acentos($key);
			$key = remove_acentos(mb_convert_encoding($key, 'UTF-8', 'ISO-8859-1'));
			$key = strtolower(trim($key));

			if (@$vls[$key] != "") {
				$vls[$key] .= "," . trim($value);
			} else {
				$vls[$key] = trim($value);
			}
		}
		if ($page == "@") {
			@$vls["foto"] = remove_acentos(@$vls["foto"]);
		}
		$tmp = "";
		$tmp .= "<div class='br-a biography'>";
		$nome = (@$vls["nome"] != "" ? $vls["nome"] : str_replace("_", " ", $page));
		if ($nome == "@") {
			$nome = "Biografia";
		}
		$tmp .= "<h4 class='mt5 mb5 mbn ph10 pb5 br-b fw500 text-center'>$nome</h4>";
		$tmp .= "<p style=\"text-align:center;\">";
		$tmp .= (@$vls["foto"] != "" ? "<a href='{{{url_site}}}imagem@" . str_to_url($vls["foto"], false) . "'><img src='imagem@" . str_to_url($vls["foto"], false) . "!img'></a>" : "");
		$tmp .= (@$vls["nascimento"] != "" ? "<div  class=\"text-center\"><i class=\"fas fa-star\"></i> " . $vls["nascimento"] . "</div>" : "");
		$tmp .= (@$vls["morte"] != "" ? "<div class=\"text-center\"><i class=\"fas fa-cross\"></i> " . $vls["morte"] . "</div>" : "");
		$tmp .= "</p>";

		$tmp .= "<table class=\"table mbn tc-med-1\"><tbody>";
		if (@$vls["cargo"] != "") {
			$c = explode(",", $vls["cargo"]);
			$a = explode(",", $vls["antecessor"] ?? "");
			$s = explode(",", $vls["sucessor"] ?? "");
			$m = explode(",", $vls["mandato"] ?? "");
			foreach ($c as $key => $cargo) {
				$tmp .= "<tr><td><span>Cargo:</span></td><td><span>$cargo</span></td></tr>";
				$tmp .= (@$m[$key] != "" ? "<tr><td style='border:0'><span>Mandato:</span></td><td style='border:0'><span>" . $m[$key] . "</span></td></tr>" : "");
				$tmp .= (@$a[$key] != "" ? "<tr><td style='border:0'><span>Antecessor:</span></td><td style='border:0'><span>[[" . $a[$key] . "]]</span></td></tr>" : "");
				$tmp .= (@$s[$key] != "" ? "<tr><td style='border:0'><span>Sucessor:</span></td><td style='border:0'><span>[[" . $s[$key] . "]]</span></td></tr>" : "");
			}
		}
		$tmp .= (@$vls["feitos"] != "" ? "<tr><td><span>Principais Feitos:</span></td><td><span>" . $vls["feitos"] . "</span></td></tr>" : "");
		$tmp .= "</tbody></table>";
		$tmp .= "</div>";
		$conteudo = str_replace($template, $tmp, $conteudo);
	}


	//VíDEO------------------------------------------------------------
	
	preg_match_all("/{{Video:(.*?)}}/is", $conteudo, $tmpl);

	
	foreach ($tmpl[1] as $chave => $valor) {
		$template = $tmpl[0][$chave];
		$tmp_p = explode("|", $valor);
		$vls = array();
		for ($i = 0; $i <= count($tmp_p) - 1; $i++) {
			if ($i == 0) {
				$value = $tmp_p[$i];
				$key = "url";
			} else {
				list($key, $value) = explode("=", $tmp_p[$i] . "=");
			}
			$key = remove_acentos($key);
			$key = remove_acentos(mb_convert_encoding($key, 'UTF-8', 'ISO-8859-1'));
			$key = strtolower(trim($key));
			$vls[$key] = $value;
		}
		if ($page == "@") {
			$vls["url"] = remove_acentos(@$vls["url"]);
		}

		if (@$vls["largura"] == "") {
			$vls["largura"] = 560;
		}
		if (@$vls["altura"] == "") {
			$vls["altura"] = 315;
		}

		$p_url = parse_url($vls["url"]);
		$msg_erro = "";

		//-- YOUTUBE ----------------------------------
		if (@$p_url["host"] == "www.youtube.com" || @$p_url["host"] == "m.youtube.com") {
			if (@$p_url["path"] == "" || @$p_url["path"] == "/") {
				$msg_erro = "V&iacute;deo n&atilde;o encontrado: URL incompleta!";
				$tmp = "<div class='video_erro' style='width:" . $vls["largura"] . "px;height:" . $vls["altura"] . ";'>$msg_erro</div>";
			} else {
				$p_url_path = explode("/", $p_url["path"]);
				$p_url_id = "";
				if (@$p_url_path[1] == "watch") {
					$tmp_p = explode("&", $p_url["query"] ?? "");
					for ($i = 0; $i <= count($tmp_p) - 1; $i++) {
						list($key, $value) = explode("=", $tmp_p[$i] . "=");
						if ($key == "v") {
							$p_url_id = $value;
						}
					}
				} elseif (@$p_url_path[1] == "embed") {
					$p_url_id = $p_url_path[2];
				}
				if ($p_url_id != "") {
					$tmp = "<iframe"
						. " width=\"" . $vls["largura"] . "\" height=\"" . $vls["altura"] . "\" "
						. " src=\"https://www.youtube.com/embed/$p_url_id?rel=0&amp;showinfo=0\" "
						. " frameborder=\"0\" "
						. " allowfullscreen>"
						. "</iframe>";
				} else {
					$msg_erro = "V&iacute;deo n&atilde;o encontrado: URL incorreta!";
					$tmp = "<div class='video_erro' style='width:" . $vls["largura"] . "px;height:" . $vls["altura"] . ";'>$msg_erro</div>";
				}
			}
		}
		//-- OUTRO ----------------------------------
		else {
			$msg_erro = "V&iacute;deo n&atilde;o encontrado: URL inv&aacute;lida!";
			$tmp = "<div class='video_erro' style='width:" . $vls["largura"] . "px;height:" . $vls["altura"] . ";'>$msg_erro</div>";
		}
		

		$conteudo = str_replace($template, $tmp, $conteudo);
	}


	//REFERÊNCIAS------------------------------------------------------------
	preg_match_all("/{{Referencias:(.*?)}}/is", $conteudo, $tmpl);
	foreach ($tmpl[1] as $chave => $valor) {
		$template = $tmpl[0][$chave];

		$tmp_p = explode("|", $valor);
		$vls = array();
		for ($i = 0; $i <= count($tmp_p) - 1; $i++) {
			$k = explode("=", $tmp_p[$i] . "=");
			$key = $k[0];
			$value = "";

			for ($j = 1; $j <= count($k) - 1; $j++) {
				$value .= ($value <> "" && $k[$j] <> "" ? "=" : "") . $k[$j];
			}

			//list($key,$value) = explode("=",$tmp_p[$i]."=");
			$key = remove_acentos($key);
			$key = remove_acentos(mb_convert_encoding($key, 'UTF-8', 'ISO-8859-1'));
			$key = strtolower(trim($key));

			if (@$vls[$key] != "") {
				$vls[$key] .= "," . trim($value);
			} else {
				$vls[$key] = trim($value);
			}
		}

		$tmp = "";
		$tmp .= "<no_text><p><hr color='#EEE' size=1>";
		$tmp .= "==" . (@$vls["titulo"] != '' ? @$vls["titulo"] : "Refer&ecirc;ncias") . "==";
		if (@$vls["texto"] != "") {
			$t = explode(",", $vls["texto"]);
			$l = explode(",", $vls["link"] ?? "");
			$tmp .= "<ol start=\"1\" style='font-weight:normal;font-size:13px;'>";
			foreach ($t as $key => $texto) {
				$tmp .= "<li><span class=\"texto\">";
				$tmp .= $texto;
				if (@$l[$key] != '') {
					//$l[$key] = str_replace("[[","",$l[$key]);
					//$l[$key] = str_replace("]]","",$l[$key]);
					//$tmp .= " - [[".$l[$key]."]]";
					$tmp .= " - " . $l[$key];
				}
				$tmp .= "</span></li>";
			}
			$tmp .= "</ol></no_text>";
		}
		$conteudo = str_replace($template, $tmp, $conteudo);
	}


	//IMAGENS------------------------------------------------------------
	$conteudo = str_ireplace("{{Imagens}}", "<div class='imagens' style='display: flex;flex-wrap: wrap;justify-content: center;'>", $conteudo);
	$conteudo = str_ireplace("{{/Imagens}}", "</div>", $conteudo);

	//IMAGEM------------------------------------------------------------
	preg_match_all("/{{Imagem:(.*?)}}/is", $conteudo, $tmpl);
	foreach ($tmpl[1] as $chave => $valor) {
		$template = $tmpl[0][$chave];
		$vls = image_template_options($valor);
		$vls["link"] = (@$vls["link"] == "" ? "imagem@" . str_to_url(@$vls["url"], false) : str_to_url($vls["link"], false));
		$vls["max_largura"] = (@$vls["largura"] == "" ? 150 : $vls["largura"]);

		$style_img = (@$vls["largura"] <> "" ? ";width:" . $vls["largura"] . "px" : "") .
			(@$vls["altura"] <> "" ? ";height:" . $vls["altura"] . "px" : "") .
			(@$vls["max_largura"] <> "" ? ";max-width:" . $vls["max_largura"] . "px" : "");

		$tmp = "<figure style='text-align:center;" .
			(@$vls["alinhamento"] <> "" ? ";float:" . $vls["alinhamento"] : "") .
			//               (@$vls["largura"]<>""?";width:".$vls["largura"]."px":";imagem@".str_to_url(@$vls["url"],false)."!WIDTH_INFO").
			//               (isset($vls["altura"])?";height:".$vls["altura"]."px":";imagem@".str_to_url(@$vls["url"],false)."!HEIGHT_INFO").
			";'>" .
			($vls["link"] != "*" && $vls["link"] != "-" ? "<a border=0 href='{{{url_site}}}" . $vls["link"] . "'>" : "") .
			"<img data-url='" . str_to_url(@$vls["url"], false) . "' src='imagem@" . str_to_url(@$vls["url"], false) . "!img' " . ($style_img != "" ? "style='$style_img'" : "") . ">" .
			($vls["link"] != "*" && $vls["link"] != "-" ? "</a>" : "") .
			(@$vls["legenda"] != "" ? "<figcaption>" . $vls["legenda"] . "</figcaption>" : "") .
			"</figure>";

		$conteudo = str_replace($template, $tmp, $conteudo);
	}
	$conteudo = str_replace("&vert;", "|", $conteudo);

	
	preg_match_all("/src=\'imagem@(.*?)!img\'/is", $conteudo, $tmpl);
	foreach ($tmpl[1] as $key => $value) {
		$template = $tmpl[0][$key];
		$img_t = "N";
		if (substr($value, -5) == "!tmpl") {
			$value = substr($value, 0, -5);
			$img_t = "S";
		}
		$rs = resolve_image($value . ($img_t === "S" ? "!tmpl" : ""));
		if (count($rs) > 0) {
			$linha = $rs;
			$file = json_decode($linha["IMAGEM"], true);
			$img = @$file["url"];
			$img_mini = @$file["url_mini"];
			$img_cp = @$file["url_cp"];

			

			$conteudo = str_replace($template, "src=\"{{{url_diretorio}}}" . $img . "?" . date("YmdHis") . "\"", $conteudo);
			if (true) {
				$img_info[0] = $file["w"];
				$img_info[1] = $file["h"];
			} else {
				$img_info[0] = 100;
				$img_info[1] = 100;
			}
			$conteudo = str_replace("imagem@" . str_to_url($value) . "!WIDTH_INFO", "width:" . $img_info[0] . "px", $conteudo);
			$conteudo = str_replace("imagem@" . str_to_url($value) . "!HEIGHT_INFO", "height:" . $img_info[1] . "px", $conteudo);
		} else {
			
			$conteudo = str_replace($template, "src=\"{{{url_diretorio}}}@imgs/img_nao_encontrada.png\"", $conteudo);
		}
	}
	return $conteudo;
}
	function marc_li($li, $start = 1)
	{
		$start = ($start == "" ? 1 : $start);
		$li = str_replace(">", " start=\"$start\">", $li);
		return $li;
	}

function page_lista_marcadores($conteudo)
{

	$linhas = explode("\n", $conteudo . "\n");
	$l = 0;
	$linhac[] = "";

	$marc_ni = "<ol>";
	$marc_nf = "</ol>";
	$marc_li = "<ul style='list-style-type: square;'>";
	$marc_lf = "</ul>";
	$marc_ai = "<ol style='list-style-type: lower-alpha;'>";
	$marc_af = "</ol>";
	$marc_ri = "<ol style='list-style-type: upper-roman;'>";
	$marc_rf = "</ol>";

	$r = "";
	$univel = 0;
	foreach ($linhas as $linha) {
		if (trim($linha) == "") {
			$linha = "&nbsp;";
		}
		$n = 0;
		$nv = 0;
		$n2 = 0;
		unset($marc2);
		for ($i = 0; $i <= strlen($linha) - 1; $i++) {
			$ch = substr($linha, $i, 1);
			$ch_t = substr($linha, $i, 3);
			if ($ch == "#" || $ch == "*" || $ch == "@" || $ch == "%") {
				$n++;
				$nv++;
			} elseif ($ch_t == "[[#" || $ch_t == "[[*" || $ch_t == "[[@" || $ch_t == "[[%") {
				$v = substr($linha, $n, strpos($linha, "]]") + (2 - $nv));
				$n2 = $n2 + strlen($v);
				$i = $i + strlen($v) - 1;
				$n = $n + $n2;
				$nv++;
				$marc2[$nv] = preg_replace("/[^0-9]/i", "", $v);
			} else {
				$marc = substr($linha, 0, $n);
				$marc = preg_replace("/[^#^@^*^%]/i", "", $marc);
				$nivel = $nv;
				$linha_n = substr($linha, $i, strlen($linha));
				$tag = false;
				if ($nivel > $univel) {
					for ($i = $univel; $i < $nivel; $i++) {
						$m[$i] = substr($marc, $i, 1);
						$r .= 	($m[$i] == "#" ?
							marc_li($marc_ni, @$marc2[$nivel]) : ($m[$i] == "*" ?
								marc_li($marc_li, @$marc2[$nivel]) : ($m[$i] == "@" ?
									marc_li($marc_ai, @$marc2[$nivel]) :
									marc_li($marc_ri, @$marc2[$nivel])
								)
							)
						) . "\n";
					}
					$tag = true;
				} elseif ($nivel < $univel) {
					for ($i = $univel - 1; $i >= $nivel; $i--) {
						$r .= 	($m[$i] == "#" ?
							$marc_nf : ($m[$i] == "*" ?
								$marc_lf : ($m[$i] == "@" ?
									$marc_af :
									$marc_rf
								)
							)
						) . "\n";
					}
					if ($nivel > 0) {
						$tag = true;
					}
				}
				if ((@$m[$nivel - 1] != substr($marc, $nivel - 1, 1) || @$marc2[$nivel] != "") && ($nivel > 0)) {
					$i = $nivel - 1;
					$mm = substr($marc, $i, 1);
					$r .= 	($m[$i] == "#" ?
						$marc_nf : ($m[$i] == "*" ?
							$marc_lf : ($m[$i] == "@" ?
								$marc_af :
								$marc_rf
							)
						)
					) . "\n" .
						($mm == "#" ?
							marc_li($marc_ni, @$marc2[$nivel]) : ($mm == "*" ?
								marc_li($marc_li, @$marc2[$nivel]) : ($mm == "@" ?
									marc_li($marc_ai, @$marc2[$nivel]) :
									marc_li($marc_ri, @$marc2[$nivel])
								)
							)
						) . "\n";
					$m[$i] = $mm;
					$tag = true;
				}
				if ($nivel > 0) {
					$r .= "<li><span class='texto'>$linha_n</span></li>\n";
					$marc_pv = false;
				} else {
					$ch = substr($linha_n, 0, 1);
					if (@$marc_pv == true && $ch == ";") {
						$marc_pv = false;
					}
					if (@$marc_pv != true && ($ch == ";" || $ch == ":")) {
						$r .= "<dl>";
						$marc_pv = true;
					} elseif (@$marc_pv == true && ($ch != ";" && $ch != ":")) {
						$r .= "</dl>";
						$marc_pv = false;
					}
					if ($ch == ";") {
						$dv = strpos($linha_n, ":");
						if ($dv === false) {
							$k = substr($linha_n, 1, strpos($linha_n, ":") - 1);
							$v = "";
						} else {
							$k = substr($linha_n, 1, strpos($linha_n, ":") - 1);
							$v = substr($linha_n, strpos($linha_n, ":") + 1, strlen($linha_n));
						}
						$r .= ($k != "" ? "<dt>$k</dt>" : $k) . ($v != "" ? "<dd>$v</dd>" : $v);
					} elseif ($ch == ":") {
						$v = substr($linha_n, 1, strlen($linha_n));
						$r .= "<dd>$v</dd>";
					} else {
						$r .= $linha_n;
						if (substr($linha_n, strlen(trim($linha_n)) - 2, 2) != "==") {
							$r .= ($tag != true ? "<br>" : "");
						};
						if (substr($linha_n, strlen(trim($linha_n)) - 2, 2) == "==") {
							$r .= ($tag != true ? " " : "");
						};
					}
				}
				$univel = $nivel;
				break;
			}
		}
		$l++;
	}

	$nivel = 0;
	if ($nivel < $univel) {
		for ($i = $univel - 1; $i >= $nivel; $i--) {
			$r .= 	($m[$i] == "#" ?
				$marc_nf : ($m[$i] == "*" ?
					$marc_lf : ($m[$i] == "@" ?
						$marc_af :
						$marc_rf
					)
				)
			);
		}
	}
	if (@$marc_pv == true) {
		$r .= "</dl>";
	}

	return $r;
}
