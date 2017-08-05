<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 *
*
* @package    local
* @subpackage paperattendance
* @copyright  2017 Jorge Cabané (jcabane@alumnos.uai.cl) 					
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
//Pertenece al plugin PaperAttendance
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->dirroot . '/local/paperattendance/locallib.php');
require_once ($CFG->dirroot . "/repository/lib.php");

require_once ($CFG->libdir . '/pdflib.php');
require_once ($CFG->dirroot . '/mod/assign/feedback/editpdf/fpdi/fpdi.php');
require_once ($CFG->dirroot . "/mod/assign/feedback/editpdf/fpdi/fpdi_bridge.php");
require_once ($CFG->dirroot . "/mod/assign/feedback/editpdf/fpdi/fpdi.php");

global $CFG, $DB, $OUTPUT, $USER, $PAGE;

// User must be logged in.
require_login();
if (isguestuser()) {
	print_error(get_string('notallowedprint', 'local_paperattendance'));
	die();
}

// Action = { view, edit, delete }, all page options.
$action = optional_param('action', 'view', PARAM_TEXT);
$categoryid = optional_param('categoryid', 406, PARAM_INT);
$sesspageid = optional_param('sesspageid', 0, PARAM_INT);
$pdfname = optional_param('pdfname', '-', PARAM_TEXT);
$sesskey = optional_param("sesskey", null, PARAM_ALPHANUM);
//Page
$page = optional_param('page', 0, PARAM_INT);
$perpage = 30;

if(is_siteadmin()){
	//if the user is an admin show everything
	$sqlmissing = "SELECT * 
					FROM {paperattendance_sessionpages}
					WHERE processed = ?
					ORDER BY id DESC";

	$countmissing = count($DB->get_records_sql($sqlmissing, array(0)));
	$missing = $DB->get_records_sql($sqlmissing, array(0), $page*$perpage,$perpage);
}
else{
	//if the user is a secretary show their own uploaded attendances
	$sqlcategory = "SELECT cc.*
					FROM {course_categories} cc
					INNER JOIN {role_assignments} ra ON (ra.userid = ?)
					INNER JOIN {role} r ON (r.id = ra.roleid)
					INNER JOIN {context} co ON (co.id = ra.contextid)
					WHERE cc.id = co.instanceid AND r.shortname = ?";
	$categoryparams = array($USER->id, "secrepaper");
	$category = $DB->get_record_sql($sqlcategory, $categoryparams);
	if($category){
		$categoryid = $category->id;
	}else{
		print_error(get_string('notallowedmissing', 'local_paperattendance'));
	}
	
	$sqlmissing = "SELECT * 
					FROM {paperattendance_sessionpages}
					WHERE processed = ? AND uploaderid = ?
					ORDER BY id DESC";
	$params = array(0, $USER->id);
	
	$countmissing = count($DB->get_records_sql($sqlmissing, $params));
	$missing = $DB->get_records_sql($sqlmissing, $params, $page*$perpage,$perpage);
}

$context = context_coursecat::instance($categoryid);
$contextsystem = context_system::instance();

if (! has_capability('local/paperattendance:missingpages', $context) && ! has_capability('local/paperattendance:missingpages', $contextsystem)) {
	print_error(get_string('notallowedmissing', 'local_paperattendance'));
}

if($countmissing==0){
	print_error(get_string('nothingmissing', 'local_paperattendance'));
}

$url = new moodle_url('/local/paperattendance/missingpages.php');

$PAGE->navbar->add(get_string('missingpages', 'local_paperattendance'));
$PAGE->navbar->add(get_string('missingpages', 'local_paperattendance'), $url);
$PAGE->set_context($contextsystem);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin ( 'ui' );
$PAGE->requires->jquery_plugin ( 'ui-css' );

if ($action == "view") {
    $missingtable = new html_table();
    if ($countmissing > 0) {
    	$missingtable->head = array(
    			get_string("hashtag", "local_paperattendance"),
        		get_string("scan", "local_paperattendance"),
    			get_string("pagenum", "local_paperattendance"),
        		get_string("uploader", "local_paperattendance"),
        		get_string("setting", "local_paperattendance"
        				));
    	
    	$counter = $page * $perpage + 1;
    	foreach ($missing as $miss) {
    		
    		//delete action
            $deletemissingurl = new moodle_url("/local/paperattendance/missingpages.php",
                    array(
                        "action" => "delete",
                    	"sesspageid" => $miss->id,
                        "sesskey" => sesskey()                    	 
                    		
                    ));
            $deletemissingicon= new pix_icon("t/delete", get_string("deletemissing", "local_paperattendance"
            		));
            $deleteactionmissing = $OUTPUT->action_icon($deletemissingurl, $deletemissingicon,
                    new confirm_action(get_string("doyouwantdeletemissing", "local_paperattendance")
                    		));
            
            //edit action
            $editurlmissing = new moodle_url("/local/paperattendance/missingpages.php",
                    array(
                        "action" => "edit",
                    	"sesspageid" => $miss->id,
                        "sesskey" => sesskey()
                    		
                    ));
            $editiconmissing = new pix_icon("i/edit", get_string("editmissing", "local_paperattendance"
            		));
            $editactionmissing = $OUTPUT->action_icon($editurlmissing, $editiconmissing,
                    new confirm_action(get_string("doyouwanteditmissing", "local_paperattendance")
                    		));
                        
            //view scan action
            $scanurl_attendance = new moodle_url("/local/paperattendance/missingpages.php", array(
            		"action" => "scan",
            		"pdfname" => $miss->pdfname,
            		"page" => ($miss->pagenum +1)
            ));
            $scanicon_attendance = new pix_icon("e/new_document", get_string('see', 'local_paperattendance'));
            $scanaction_attendance = $OUTPUT->action_icon(
            		$scanurl_attendance,
            		$scanicon_attendance
            		);
            
            //get username
            $username = paperattendance_getusername($miss->uploaderid);
            
            //add data to table
            $missingtable->data [] = array(
            	$counter,	
            	$scanaction_attendance,
            	$miss->pagenum +1,
            	$username,
                $deleteactionmissing . $editactionmissing);
            
            $counter++;
        }
    }
    
    $PAGE->set_title(get_string("viewmissing", "local_paperattendance"));
    $PAGE->set_heading(get_string("viewmissing", "local_paperattendance"));
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("viewmissingtitle", "local_paperattendance"));
  
    echo html_writer::table($missingtable);  
}

if ($action == "edit") {
	if ($sesspageid == null) {
		print_error(get_string("sessdoesnotexist", "local_attendance"));
		$action = "view";
	}
	else {
		if ($session = $DB->get_record("paperattendance_sessionpages", array("id" => $sesspageid))){
			
			$timepdf = time();
			$path = $CFG -> dataroot. "/temp/local/paperattendance/";
			$attendancepdffile = $path . "/print/paperattendance_".$sesspageid."_".$timepdf.".pdf";
			
			$pdfpath = $CFG -> dataroot. "/temp/local/paperattendance/unread/".$session->pdfname;
			$viewerstart = $session->pagenum + 1;
			
			$pdf = new FPDI();
			//open the full pdf
			$pdf->setSourceFile($pdfpath);
			// import a page
			$templateId = $pdf->importPage($viewerstart);
			// get the size of the imported page
			$size = $pdf->getTemplateSize($templateId);
			//Add page on portrait position
			$pdf->AddPage('P', array($size['w'], $size['h']));
			// use the imported page
			$pdf->useTemplate($templateId);
			//write the file
			$pdf->Output($attendancepdffile, "F");
			
			$fs = get_file_storage();
			$file_record = array(
					'contextid' => $contextsystem->id,
					'component' => 'local_paperattendance',
					'filearea' => 'scan',
					'itemid' => 0,
					'filepath' => '/',
					'filename' => "paperattendance_".$sesspageid."_".$timepdf.".pdf"
			);
			// If the file already exists we delete it
			if ($fs->file_exists($contextsystem->id, 'local_paperattendance', 'scan', 0, '/', "paperattendance_".$sesspageid."_".$timepdf.".pdf")) {
				$previousfile = $fs->get_file($contextsystem->id, 'local_paperattendance', 'scan', 0, '/', "paperattendance_".$sesspageid."_".$timepdf.".pdf");
				$previousfile->delete();
			}
			// Info for the new file
			$fileinfo = $fs->create_file_from_pathname($file_record, $attendancepdffile);
			$url = moodle_url::make_pluginfile_url($contextsystem->id, 'local_paperattendance', 'scan', 0, '/', "paperattendance_".$sesspageid."_".$timepdf.".pdf");
			$viewerpdf = html_writer::nonempty_tag("embed", " ", array(
					"src" => $url,
					"style" => "height:50vh; width:100%vw; float:left"
			));
			
			unlink($attendancepdffile);
			
			$inputs = html_writer::div('<label for="course">Shortname del Curso:</label><input type="text" class="form-control" id="course" placeholder="2113-V-ECO121-1-1-2017"><button type="button" class="btn btn-info btn-xs" data-toggle="modal" data-target="#shortnamemodal">?</button>',"form-group", array("style"=>"float:right; margin-right:10%"));
			$inputs .= html_writer::div('<label for="date">Fecha:</label><input type="text" class="form-control" id="date" placeholder="01-08-2017"><button type="button" class="btn btn-info btn-xs" data-toggle="modal" data-target="#datemodal">?</button>',"form-group", array("style"=>"float:right; margin-right:10%"));
			$inputs .= html_writer::div('<label for="module">Hora Módulo:</label><input type="text" class="form-control" id="module" placeholder="16:30"><button type="button" class="btn btn-info btn-xs" data-toggle="modal" data-target="#modulemodal">?</button>',"form-group", array("style"=>"float:right; margin-right:10%"));
			$inputs .= html_writer::div('<label for="begin">Inicio Lista:</label><input type="text" class="form-control" id="begin" placeholder="27"><button type="button" class="btn btn-info btn-xs" data-toggle="modal" data-target="#beginmodal">?</button>',"form-group", array("style"=>"float:right; margin-right:10%"));
			$inputs .= html_writer::div('<button type="submit" id="confirm" class="btn btn-default">Continuar</button>',"form-group", array("style"=>"float:right; margin-right:10%"));
			
			$shortnamemodal = '<div class="modal fade" id="shortnamemodal" role="dialog" style="width: 50vw;">
							    <div class="modal-dialog modal-sm">
							      <div class="modal-content">
							        <div class="modal-body">
									  <div class="alert alert-info">Escriba el <strong>curso</strong> perteneciente a su lista escaneada</div>
									  <img class="img-responsive" src="img/hshortname.png"> 
							        </div>
							        <div class="modal-footer">
							          <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
							        </div>
							      </div>
							    </div>
							  </div>';
			$datemodal = '<div class="modal fade" id="datemodal" role="dialog" style="width: 50vw;">
							    <div class="modal-dialog modal-sm">
							      <div class="modal-content">
							        <div class="modal-body">
									  <div class="alert alert-info">Escriba la <strong>fecha</strong> perteneciente a su lista escaneada</div>
									  <img class="img-responsive" src="img/helpdate.png">
							        </div>
							        <div class="modal-footer">
							          <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
							        </div>
							      </div>
							    </div>
							  </div>';
			$modulemodal = '<div class="modal fade" id="modulemodal" role="dialog" style="width: 50vw;">
							    <div class="modal-dialog modal-sm">
							      <div class="modal-content">
							        <div class="modal-body">
									  <div class="alert alert-info">Escriba la <strong>hora del módulo</strong> perteneciente a su lista escaneada</div>
									  <img class="img-responsive" src="img/helpmodule.png">
							        </div>
							        <div class="modal-footer">
							          <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
							        </div>
							      </div>
							    </div>
							  </div>';
			$beginmodal = '<div class="modal fade" id="beginmodal" role="dialog" style="width: 50vw;">
							    <div class="modal-dialog modal-sm">
							      <div class="modal-content">
							        <div class="modal-body">
									  <div class="alert alert-info">Escriba el <strong>nº de inicio</strong> perteneciente a su lista escaneada</div>
									  <img class="img-responsive" src="img/helpbegin.png">
							        </div>
							        <div class="modal-footer">
							          <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
							        </div>
							      </div>
							    </div>
							  </div>';
			
			$inputs .= html_writer::div($shortnamemodal, "form-group");
			$inputs .= html_writer::div($datemodal, "form-group");
			$inputs .= html_writer::div($modulemodal, "form-group");
			$inputs .= html_writer::div($beginmodal, "form-group");
		}
		else {
			print_error(get_string("missingpagesdoesnotexist", "local_paperattendance"));
			$action = "view";
			$url = new moodle_url('/local/paperattendance/missingpages.php');
			redirect($url);
		}

	}
	
	$PAGE->set_title(get_string("missingpages", "local_paperattendance"));
	$PAGE->set_heading(get_string("missingpages", "local_paperattendance"));
	echo $OUTPUT->header();
	echo $OUTPUT->heading(get_string("missingpagestitle", "local_paperattendance"));
	
	echo html_writer::div('<style>
							.form-control::-webkit-input-placeholder { color: lightgrey; }  /* WebKit, Blink, Edge */
							.form-control:-moz-placeholder { color: lightgrey; }  /* Mozilla Firefox 4 to 18 */
							.form-control::-moz-placeholder { color: lightgrey; }  /* Mozilla Firefox 19+ */
							.form-control:-ms-input-placeholder { color: lightgrey; }  /* Internet Explorer 10-11 */
							.form-control::-ms-input-placeholder { color: lightgrey; }  /* Microsoft Edge *

							.inputs-control {padding-bottom: 10%;}
							</style>');
	echo html_writer::div(get_string("missingpageshelp","local_paperattendance"),"alert alert-info", array("role"=>"alert"));
  	$pdfarea = html_writer::div($viewerpdf,"col-md-12", array( "id"=>"pdfviewer"));
  	$inputarea = html_writer::div($inputs,"col-md-12 row inputs-control", array( "id"=>"inputs"));
 	echo html_writer::div($inputarea.$pdfarea, "form-group");
	
}

if ($action == "delete") {
	if ($sesspageid == null) {
		print_error(get_string("missingdoesnotexist", "local_paperattendance"));
		$action = "view";
	}
	else {
		if ($session = $DB->get_record("paperattendance_sessionpages", array("id" => $sesspageid))) {
				if ($sesskey == $USER->sesskey) {
					$DB->delete_records("paperattendance_sessionpages", array("id" => $sesspageid));
					$action = "view";
				}
				else {
					print_error(get_string("usernotloggedin", "local_paperattendance"));
				}
		}
		else {
			print_error(get_string("missingdoesnotexist", "local_paperattendance"));
			$action = "view";
		}
	}
	$url = new moodle_url('/local/paperattendance/missingpages.php');
	redirect($url);
}

if($action == "scan"){
	
	$backurl = new moodle_url("/local/paperattendance/missingpages.php", array(
			"action" => "view"
	));
	
	$viewbackbutton = html_writer::nonempty_tag(
			"div",
			$OUTPUT->single_button($backurl, get_string('back', 'local_paperattendance')),
			array("align" => "left"
			));
	
	$url = moodle_url::make_pluginfile_url($contextsystem->id, 'local_paperattendance', 'draft', 0, '/', $pdfname);
	
	$viewerpdf = html_writer::nonempty_tag("embed", " ", array(
			"src" => $url."#page=".$page,
			"style" => "height:100vh; width:60vw"
	));
	
	$PAGE->set_title(get_string("missingpages", "local_paperattendance"));
	$PAGE->set_heading(get_string("missingpages", "local_paperattendance"));
	echo $OUTPUT->header();
	echo $OUTPUT->heading(get_string("missingpagestitle", "local_paperattendance"));
	
	echo $viewbackbutton;
	echo $viewerpdf;
	
}

echo $OUTPUT->footer();

?>

<script>
var sessinfo = [];

$( "#confirm" ).on( "click", function() {
	var course = $('#course');
	var date = $('#date');
	var module = $('#module');
	var begin = $('#begin');
	var sesspageid = <?php echo $sesspageid; ?>;

	if (!course.val() || !date.val() || !module.val() || !begin.val() || (parseFloat(begin.val())-1+26)%26 != 0 || date.val() === date.val().split('-')[0] || module.val() === module.val().split(':')[0]) {
	    alert("Por favor, rellene todos los campos correctamente");
	}
	else{
		$.ajax({
		    type: 'GET',
		    url: 'ajax/ajaxquerys.php',
		    data: {
			      'action' : 'getliststudentspage',
			      'result' : course.val(),
			      'begin' : begin.val()
		    	},
		    success: function (response) {
		        var error = response["error"];
		        if (error != 0){
					alert(error);
		        }
		        else{
		        	sessinfo.push({"sesspageid":sesspageid, "shortname":course.val(), "date": date.val(), "module": module.val(), "begin": begin.val()});

					$("#inputs").empty();
				    var table = '<table class="table table-hover table-condensed table-responsive table-striped" style="float:right; width:40%"><thead><tr><th>#</th><th>Asistencia</th><th>Alumno</th></tr></thead><tbody id="appendtrs">';
				    $("#inputs").append(table);
			        $.each(response["alumnos"], function(i, field){
				        var counter = i + parseFloat(begin.val());
			        	var appendcheckbox = '<tr class="usercheckbox"><td>'+counter+'</td><td><input type="checkbox" value="'+field["studentid"]+'"></td><td>'+field["username"]+'</td></tr>';
			        	$("#appendtrs").append(appendcheckbox);
			        });
			        $("#inputs").append("</tbody></table>");
		    		$("#inputs").append('<button class="btn btn-info savestudentsattendance" style="float:right; width:30%">Guardar Asistencia</button>');
		    		RefreshSomeEventListener();
		        }
		    }
		});
	}
});

function RefreshSomeEventListener() {
	$( ".savestudentsattendance" ).on( "click", function() {

		var studentsattendance = [];
		
		var checkbox = $('input:checkbox');
		$.each(checkbox, function(i, field){
			var currentcheckbox = $(this);
			if(currentcheckbox.prop("checked") == true){
				var presence = 1;
			}
			else{
				var presence = 0;
			}
			studentsattendance.push({"userid":currentcheckbox.val(), "presence": presence});
		});	
		//console.log(JSON.stringify(studentsattendance));
		//console.log(JSON.stringify(sessinfo));

		$("#inputs").empty();
		$("#inputs").append("<div id='loader'><img src='img/loading.gif'></div>");
		$.ajax({
		    type: 'POST',
		    url: 'ajax/ajaxquerys.php',
		    data: {
			      'action' : 'savestudentsattendance',
			      'sessinfo' : sessinfo,
			      'studentsattendance' : studentsattendance
		    	},
		    success: function (response) {
				var error = response["sesion"];
				var error2 = response["sesiondos"];
				var error3 = response["guardar"];
				var error4 = response["omegatoken"];
				var error5 = response["omegatoken2"];
				var error6 = response["arregloalumnos"];
				var error7 = response["idcurso"];
				var error8 = response["idsesion"];
				var moodleurl = "<?php echo $CFG->wwwroot;?>";
				$('#loader').hide();
				$("#inputs").html('<div class="alert alert-success" role="alert" style="float:right; width:40%">'+error+error2+error3+error4+error5+error6+error7+error8+'</div>');
				$("#inputs").append('<a href="'+moodleurl+'/local/paperattendance/missingpages.php" class="btn btn-info" role="button" style="float:right; width:40%">Volver</button>');
				
		    }
		});
	});
}
</script>