<?php
//IMathAS:  Frontend of testing engine - manages administration of assessments
//(c) 2006 David Lippman

	require("../validate.php");
	if (isset($guestid)) {
		$teacherid=$guestid;
	}
	if (!isset($sessiondata['sessiontestid']) && !isset($teacherid) && !isset($tutorid) && !isset($studentid)) {
		echo "<html><body>";
		echo _("You are not authorized to view this page.  If you are trying to reaccess a test you've already started, access it from the course page");
		echo "</body></html>\n";
		exit;
	}
	$actas = false;
	$isreview = false;
	if (isset($teacherid) && isset($_GET['actas'])) {
		$userid = $_GET['actas'];
		unset($teacherid);
		$actas = true;
	}
	include("displayq2.php");
	include("testutil.php");
	include("asidutil.php");
	
	$inexception = false;
	$exceptionduedate = 0;
	//error_reporting(0);  //prevents output of error messages
	
	//check to see if test starting test or returning to test
	if (isset($_GET['id'])) {
		//check dates, determine if review
		$aid = $_GET['id'];
		$isreview = false;

		// SELECT#01
		$query = "SELECT deffeedback,startdate,enddate,reviewdate,shuffle,itemorder,password,avail,isgroup,groupsetid,deffeedbacktext,timelimit,courseid,istutorial,name FROM imas_assessments WHERE id='$aid'";
		$result = mysql_query($query) or die("Query failed : $query: " . mysql_error());
		$adata = mysql_fetch_array($result, MYSQL_ASSOC);
		$now = time();
		$assessmentclosed = false;
		
		if ($adata['avail']==0 && !isset($teacherid) && !isset($tutorid)) {
			$assessmentclosed = true;
		}
		
		if (!$actas) { 
		  if (isset($studentid)) {
		    // INSERT#01
				$query = "INSERT INTO imas_content_track (userid,courseid,type,typeid,viewtime) VALUES ";
				$query .= "('$userid','$cid','assess','$aid',$now)";
				mysql_query($query) or die("Query failed : " . mysql_error());
			}

			// SELECT#02
			$query = "SELECT startdate,enddate FROM imas_exceptions WHERE userid='$userid' AND assessmentid='$aid'";
			$result2 = mysql_query($query) or die("Query failed : " . mysql_error());
			$row = mysql_fetch_row($result2);
			if ($row!=null) {
				if ($now<$row[0] || $row[1]<$now) { //outside exception dates
					if ($now > $adata['startdate'] && $now<$adata['reviewdate']) {
						$isreview = true;
					} else {
						if (!isset($teacherid) && !isset($tutorid)) {
							$assessmentclosed = true;
						}
					}
				} else { //inside exception dates exception
					if ($adata['enddate']<$now) { //exception is for past-due-date
						$inexception = true; //only trigger if past due date for penalty
					}
				}
				$exceptionduedate = $row[1];
			} else { //has no exception
				if ($now < $adata['startdate'] || $adata['enddate']<$now) { //outside normal dates
					if ($now > $adata['startdate'] && $now<$adata['reviewdate']) {
						$isreview = true;
					} else {
						if (!isset($teacherid) && !isset($tutorid)) {
							$assessmentclosed = true;
						}
					}
				}
			}
		}
		if ($assessmentclosed) {
			require("header.php");
			echo '<p>', _('This assessment is closed'), '</p>';
			if (isset($sessiondata['ltiitemtype']) && $sessiondata['ltiitemtype']==0 && $sessiondata['ltiitemid']==$aid) {
				//in LTI and right item
				list($atype,$sa) = explode('-',$adata['deffeedback']);
				if ($sa!='N') {
				  // SELECT#03
					$query = "SELECT id FROM imas_assessment_sessions WHERE userid='$userid' AND assessmentid='$aid' ORDER BY id LIMIT 1";
					$result = mysql_query($query) or die("Query failed : $query " . mysql_error());
					if (mysql_num_rows($result)>0) {
						echo '<p><a href="../course/gb-viewasid.php?cid='.$cid.'&asid='.mysql_result($result,0,0).'">', _('View your assessment'), '</p>';
					}
				}
			}
			require("../footer.php");
			exit;
		} 
		
		//check for password
		if (trim($adata['password'])!='' && !isset($teacherid) && !isset($tutorid)) { //has passwd
			$pwfail = true;
			if (isset($_POST['password'])) {
				if (trim($_POST['password'])==trim($adata['password'])) {
					$pwfail = false;
				} else {
					$out = '<p>' . _('Password incorrect.  Try again.') . '<p>';
				}
			} 
			if ($pwfail) {
				require("../header.php");
				if (!$isdiag && strpos($_SERVER['HTTP_REFERER'],'treereader')===false && !(isset($sessiondata['ltiitemtype']) && $sessiondata['ltiitemtype']==0)) {
					echo "<div class=breadcrumb>$breadcrumbbase <a href=\"../course/course.php?cid={$_GET['cid']}\">{$sessiondata['coursename']}</a> ";
					echo '&gt; ', _('Assessment'), '</div>';
				}
				echo $out;
				echo '<h2>'.$adata['name'].'</h2>';
				echo '<p>', _('Password required for access.'), '</p>';
				echo "<form method=\"post\" enctype=\"multipart/form-data\" action=\"showtest.php?cid={$_GET['cid']}&amp;id={$_GET['id']}\">";
				echo "<p>Password: <input type=\"password\" name=\"password\" /></p>";
				echo '<input type=submit value="', _('Submit'), '" />';
				echo "</form>";
				require("../footer.php");
				exit;
			}
		}
	
		//get latepass info
		if (!isset($teacherid) && !isset($tutorid) && !$actas && !isset($sessiondata['stuview'])) {
		  // SELECT#04
		   $query = "SELECT latepass FROM imas_students WHERE userid='$userid' AND courseid='{$adata['courseid']}'";
		   $result = mysql_query($query) or die("Query failed : $query " . mysql_error());
		   $sessiondata['latepasses'] = mysql_result($result,0,0);
		} else {
			$sessiondata['latepasses'] = 0;
		}
		
		$sessiondata['istutorial'] = $adata['istutorial'];
		$_SESSION['choicemap'] = array();

		// SELECT#05
		$query = "SELECT id,agroupid,lastanswers,bestlastanswers,starttime FROM imas_assessment_sessions WHERE userid='$userid' AND assessmentid='{$_GET['id']}' ORDER BY id DESC LIMIT 1";
		$result = mysql_query($query) or die("Query failed : " . mysql_error());
		$line = mysql_fetch_array($result, MYSQL_ASSOC);
		
		if ($line == null) { //starting test
			//get question set
			
			if (trim($adata['itemorder'])=='') {
				echo _('No questions in assessment!');
				exit;
			}
			
			list($qlist,$seedlist,$reviewseedlist,$scorelist,$attemptslist,$lalist) = generateAssessmentData($adata['itemorder'],$adata['shuffle'],$aid);
			
			if ($qlist=='') {  //assessment has no questions!
				echo '<html><body>', _('Assessment has no questions!');
				echo "</body></html>\n";
				exit;
			} 
			
			$bestscorelist = $scorelist.';'.$scorelist.';'.$scorelist;  //bestscores;bestrawscores;firstscores
			$scorelist = $scorelist.';'.$scorelist;  //scores;rawscores  - also used as reviewscores;rawreviewscores
			$bestattemptslist = $attemptslist;
			$bestseedslist = $seedlist;
			$bestlalist = $lalist;
			
			$starttime = time();
			
			$stugroupid = 0;
			if ($adata['isgroup']>0 && !$isreview && !isset($teacherid) && !isset($tutorid)) {
			  // SELECT#06
				$query = 'SELECT i_sg.id FROM imas_stugroups as i_sg JOIN imas_stugroupmembers as i_sgm ON i_sg.id=i_sgm.stugroupid ';
				$query .= "WHERE i_sgm.userid='$userid' AND i_sg.groupsetid={$adata['groupsetid']}";
				$result = mysql_query($query) or die("Query failed : " . mysql_error());
				if (mysql_num_rows($result)>0) {
					$stugroupid = mysql_result($result,0,0);
					$sessiondata['groupid'] = $stugroupid;
				} else {
					if ($adata['isgroup']==3) {
						echo "<html><body>", _('You are not yet a member of a group.  Contact your instructor to be added to a group.'), "  <a href=\"$imasroot/course/course.php?cid={$_GET['cid']}\">Back</a></body></html>";
						exit;
					}
					// INSERT#02
					$query = "INSERT INTO imas_stugroups (name,groupsetid) VALUES ('Unnamed group',{$adata['groupsetid']})";
					$result = mysql_query($query) or die("Query failed : " . mysql_error());
					$stugroupid = mysql_insert_id();
					//if ($adata['isgroup']==3) {
					//	$sessiondata['groupid'] = $stugroupid;
					//} else {
						$sessiondata['groupid'] = 0;  //leave as 0 to trigger adding group members
						//}
						// INSERT#03
					$query = "INSERT INTO imas_stugroupmembers (userid,stugroupid) VALUES ('$userid',$stugroupid)";
					mysql_query($query) or die("Query failed : " . mysql_error());
				}
					
			}
			$deffeedbacktext = addslashes($adata['deffeedbacktext']);
			if (isset($sessiondata['lti_lis_result_sourcedid']) && strlen($sessiondata['lti_lis_result_sourcedid'])>1) {
				$ltisourcedid = addslashes(stripslashes($sessiondata['lti_lis_result_sourcedid'].':|:'.$sessiondata['lti_outcomeurl'].':|:'.$sessiondata['lti_origkey'].':|:'.$sessiondata['lti_keylookup']));
			} else {
				$ltisourcedid = '';
			}
			// INSERT#04
			$query = "INSERT INTO imas_assessment_sessions (userid,assessmentid,questions,seeds,scores,attempts,lastanswers,starttime,bestscores,bestattempts,bestseeds,bestlastanswers,reviewscores,reviewattempts,reviewseeds,reviewlastanswers,agroupid,feedback,lti_sourcedid) ";
			$query .= "VALUES ('$userid','{$_GET['id']}','$qlist','$seedlist','$scorelist','$attemptslist','$lalist',$starttime,'$bestscorelist','$bestattemptslist','$bestseedslist','$bestlalist','$scorelist','$attemptslist','$reviewseedlist','$lalist',$stugroupid,'$deffeedbacktext','$ltisourcedid');";
			$result = mysql_query($query);
			if ($result===false) {
				echo 'Error DupASID. <a href="showtest.php?cid='.$cid.'&aid='.$aid.'">Try again</a>';
			}
			$sessiondata['sessiontestid'] = mysql_insert_id();
			
			if ($stugroupid==0) {
				$sessiondata['groupid'] = 0;
			} else {
			  //if a group assessment and already in a group, we'll create asids for all the group members now
			  // SELECT#07
				$query = "SELECT userid FROM imas_stugroupmembers WHERE stugroupid=$stugroupid AND userid<>$userid";
				$result = mysql_query($query) or die("Query failed : " . mysql_error());
				// INSERT#05
				$query = "INSERT INTO imas_assessment_sessions (userid,assessmentid,questions,seeds,scores,attempts,lastanswers,starttime,bestscores,bestattempts,bestseeds,bestlastanswers,reviewscores,reviewattempts,reviewseeds,reviewlastanswers,agroupid,feedback) VALUES ";
				$cnt = 0;
				if (mysql_num_rows($result)>0) {
					while ($row = mysql_fetch_row($result)) {
						if ($cnt>0) {$query .= ',';}
						$query .= "('{$row[0]}','{$_GET['id']}','$qlist','$seedlist','$scorelist','$attemptslist','$lalist',$starttime,'$bestscorelist','$bestattemptslist','$bestseedslist','$bestlalist','$scorelist','$attemptslist','$reviewseedlist','$lalist',$stugroupid,'$deffeedbacktext')";
						$cnt++;
					}
					mysql_query($query) or die("Query failed : " . mysql_error());
				}
		
			}
			$sessiondata['isreview'] = $isreview;
			if (isset($teacherid) || isset($tutorid) || $actas) {
				$sessiondata['isteacher']=true;
			} else {
				$sessiondata['isteacher']=false;
			}
			if ($actas) {
				$sessiondata['actas']=$_GET['actas'];
				$sessiondata['isreview'] = false;
			} else {
				unset($sessiondata['actas']);
			}
			if (strpos($_SERVER['HTTP_REFERER'],'treereader')!==false) {
				$sessiondata['intreereader'] = true;
			} else {
				$sessiondata['intreereader'] = false;
			}

			// SELECT#08
			$query = "SELECT name,theme,topbar,msgset,toolset FROM imas_courses WHERE id='{$_GET['cid']}'";
			$result = mysql_query($query) or die("Query failed : $query: " . mysql_error());
			$sessiondata['courseid'] = intval($_GET['cid']);
			$sessiondata['coursename'] = mysql_result($result,0,0);
			$sessiondata['coursetheme'] = mysql_result($result,0,1);
			$sessiondata['coursetopbar'] =  mysql_result($result,0,2);
			$sessiondata['msgqtoinstr'] = (floor( mysql_result($result,0,3)/5))&2;
			$sessiondata['coursetoolset'] = mysql_result($result,0,4);
			if (isset($studentinfo['timelimitmult'])) {
				$sessiondata['timelimitmult'] = $studentinfo['timelimitmult'];
			} else {
				$sessiondata['timelimitmult'] = 1.0;
			}
			
			writesessiondata();
			session_write_close();
			header('Location: ' . $urlmode  . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/showtest.php");
			exit;
		} else { //returning to test
			
			$deffeedback = explode('-',$adata['deffeedback']);
			//removed: $deffeedback[0] == "Practice" || 
			if ($myrights<6 || isset($teacherid) || isset($tutorid)) {  // is teacher or guest - delete out out assessment session
				require_once("../includes/filehandler.php");
				//deleteasidfilesbyquery(array('userid'=>$userid,'assessmentid'=>$aid),1);
				deleteasidfilesbyquery2('userid',$userid,$aid,1);
				// DELETE#01
				$query = "DELETE FROM imas_assessment_sessions WHERE userid='$userid' AND assessmentid='$aid' LIMIT 1";
				$result = mysql_query($query) or die("Query failed : $query: " . mysql_error());
				header('Location: ' . $urlmode  . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/showtest.php?cid={$_GET['cid']}&id=$aid");
				exit;
			}
			//Return to test.
			$sessiondata['sessiontestid'] = $line['id'];
			$sessiondata['isreview'] = $isreview;
			if (isset($teacherid) || isset($tutorid) || $actas) {
				$sessiondata['isteacher']=true;
			} else {
				$sessiondata['isteacher']=false;
			}
			if ($actas) {
				$sessiondata['actas']=$_GET['actas'];
				$sessiondata['isreview'] = false;
			} else {
				unset($sessiondata['actas']);
			}
			
			if ($adata['isgroup']==0 || $line['agroupid']>0) {
				$sessiondata['groupid'] = $line['agroupid'];
			} else if (!isset($teacherid) && !isset($tutorid)) { //isgroup>0 && agroupid==0
			  //already has asid, but broken from group
			  // INSERT#06
				$query = "INSERT INTO imas_stugroups (name,groupsetid) VALUES ('Unnamed group',{$adata['groupsetid']})";
				$result = mysql_query($query) or die("Query failed : " . mysql_error());
				$stugroupid = mysql_insert_id();
				if ($adata['isgroup']==3) {
					$sessiondata['groupid'] = $stugroupid;
				} else {
					$sessiondata['groupid'] = 0;  //leave as 0 to trigger adding group members
				}
				// INSERT#07
				$query = "INSERT INTO imas_stugroupmembers (userid,stugroupid) VALUES ('$userid',$stugroupid)";
				mysql_query($query) or die("Query failed : " . mysql_error());

				// UPDATE#01
				$query = "UPDATE imas_assessment_sessions SET agroupid=$stugroupid WHERE id={$line['id']}";
				mysql_query($query) or die("Query failed : " . mysql_error());
			}

			// SELECT#09
			$query = "SELECT name,theme,topbar,msgset,toolset FROM imas_courses WHERE id='{$_GET['cid']}'";
			$result = mysql_query($query) or die("Query failed : $query: " . mysql_error());
			$sessiondata['courseid'] = intval($_GET['cid']);
			$sessiondata['coursename'] = mysql_result($result,0,0);
			$sessiondata['coursetheme'] = mysql_result($result,0,1);
			$sessiondata['coursetopbar'] =  mysql_result($result,0,2);
			$sessiondata['msgqtoinstr'] = (floor( mysql_result($result,0,3)/5))&2;
			$sessiondata['coursetoolset'] = mysql_result($result,0,4);
			if (isset($studentinfo['timelimitmult'])) {
				$sessiondata['timelimitmult'] = $studentinfo['timelimitmult'];
			} else {
				$sessiondata['timelimitmult'] = 1.0;
			}
			
			if (isset($sessiondata['lti_lis_result_sourcedid'])) {
				$altltisourcedid = stripslashes($sessiondata['lti_lis_result_sourcedid'].':|:'.$sessiondata['lti_outcomeurl'].':|:'.$sessiondata['lti_origkey'].':|:'.$sessiondata['lti_keylookup']);
				if ($altltisourcedid != $line['lti_sourcedid']) {
				  $altltisourcedid = addslashes($altltisourcedid);
				  // UPDATE#02
					$query = "UPDATE imas_assessment_sessions SET lti_sourcedid='$altltisourcedid' WHERE id='{$line['id']}'";
					mysql_query($query) or die("Query failed : $query: " . mysql_error());
				}
			}
			
			
			writesessiondata();
			session_write_close();
			header('Location: ' . $urlmode  . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/showtest.php");
		}
		exit;
	} 
	
	//already started test
	if (!isset($sessiondata['sessiontestid'])) {
		echo "<html><body>", _('Error.  Access test from course page'), "</body></html>\n";
		exit;
	}
	$testid = addslashes($sessiondata['sessiontestid']);
	$asid = $testid;
	$isteacher = $sessiondata['isteacher'];
	if (isset($sessiondata['actas'])) {
		$userid = $sessiondata['actas'];
	}
        // SELECT#10
	$query = "SELECT * FROM imas_assessment_sessions WHERE id='$testid'";
	$result = mysql_query($query) or die("Query failed : $query: " . mysql_error());
	$line = mysql_fetch_array($result, MYSQL_ASSOC);
	$questions = explode(",",$line['questions']);

	$seeds = explode(",",$line['seeds']);
	if (strpos($line['scores'],';')===false) {
		$scores = explode(",",$line['scores']);
		$noraw = true;
		$rawscores = $scores;
	} else {
		$sp = explode(';',$line['scores']);
		$scores = explode(',', $sp[0]);
		$rawscores = explode(',', $sp[1]);
		$noraw = false;
	}
		
	$attempts = explode(",",$line['attempts']);
	$lastanswers = explode("~",$line['lastanswers']);
	if ($line['timeontask']=='') {
		$timesontask = array_fill(0,count($questions),'');
	} else {
		$timesontask = explode(',',$line['timeontask']);
	}
	$lti_sourcedid = $line['lti_sourcedid'];
	
	if (trim($line['reattempting'])=='') {
		$reattempting = array();
	} else {
		$reattempting = explode(",",$line['reattempting']);
	}

	$bestseeds = explode(",",$line['bestseeds']);
	if ($noraw) {
		$bestscores = explode(',',$line['bestscores']);
		$bestrawscores = $bestscores;
		$firstrawscores = $bestscores;
	} else {
		$sp = explode(';',$line['bestscores']);
		$bestscores = explode(',', $sp[0]);
		$bestrawscores = explode(',', $sp[1]);
		$firstrawscores = explode(',', $sp[2]);
	}
	$bestattempts = explode(",",$line['bestattempts']);
	$bestlastanswers = explode("~",$line['bestlastanswers']);
	$starttime = $line['starttime'];
	
	if ($starttime == 0) {
	  $starttime = time();
	  // UPDATE#03
		$query = "UPDATE imas_assessment_sessions SET starttime=$starttime WHERE id='$testid'";
		mysql_query($query) or die("Query failed : $query: " . mysql_error());
	}

        // SELECT#11
	$query = "SELECT * FROM imas_assessments WHERE id='{$line['assessmentid']}'";
	$result = mysql_query($query) or die("Query failed : $query: " . mysql_error());
	$testsettings = mysql_fetch_array($result, MYSQL_ASSOC);
	if ($testsettings['displaymethod']=='VideoCue' && $testsettings['viddata']=='') {
		$testsettings['displaymethod']= 'Embed';
	}
	if (!$isteacher) {
		$rec = "data-base=\"assessintro-{$line['assessmentid']}\" ";
		$testsettings['intro'] = str_replace('<a ','<a '.$rec, $testsettings['intro']);
	}
	$timelimitkickout = ($testsettings['timelimit']<0);
	$testsettings['timelimit'] = abs($testsettings['timelimit']);
	//do time limit mult
	$testsettings['timelimit'] *= $sessiondata['timelimitmult'];
	
	list($testsettings['testtype'],$testsettings['showans']) = explode('-',$testsettings['deffeedback']);
	
	//if submitting, verify it's the correct assessment
	if (isset($_POST['asidverify']) && $_POST['asidverify']!=$testid) {
		echo "<html><body>", _('Error.  It appears you have opened another assessment since you opened this one. ');
		echo _('Only one open assessment can be handled at a time. Please reopen the assessment and try again. ');
		echo "<a href=\"../course/course.php?cid={$testsettings['courseid']}\">", _('Return to course page'), "</a>";
		echo '</body></html>';
		exit;
	}
	//verify group is ok
	if ($testsettings['isgroup']>0 && !$isteacher &&  ($line['agroupid']==0 || ($sessiondata['groupid']>0 && $line['agroupid']!=$sessiondata['groupid']))) {
		echo "<html><body>", _('Error.  Looks like your group has changed for this assessment. Please reopen the assessment and try again.');
		echo "<a href=\"../course/course.php?cid={$testsettings['courseid']}\">", _('Return to course page'), "</a>";
		echo '</body></html>';
		exit;
	}
	
	
	$now = time();
	//check for dates - kick out student if after due date
	//if (!$isteacher) {
	if ($testsettings['avail']==0 && !$isteacher) {
		echo _('Assessment is closed');
		leavetestmsg();
		exit;
	}
           if (!isset($sessiondata['actas'])) {
	     // SELECT#12
		$query = "SELECT startdate,enddate FROM imas_exceptions WHERE userid='$userid' AND assessmentid='{$line['assessmentid']}'";
		$result2 = mysql_query($query) or die("Query failed : " . mysql_error());
		$row = mysql_fetch_row($result2);
		if ($row!=null) {
			if ($now<$row[0] || $row[1]<$now) { //outside exception dates
				if ($now > $testsettings['startdate'] && $now<$testsettings['reviewdate']) {
					$isreview = true;
				} else {
					if (!$isteacher) {
						echo _('Assessment is closed');
						leavetestmsg();
						exit;
					}
				}
			} else { //in exception
				if ($testsettings['enddate']<$now) { //exception is for past-due-date
					$inexception = true;	
				}
			}
			$exceptionduedate = $row[1];
		} else { //has no exception
			if ($now < $testsettings['startdate'] || $testsettings['enddate'] < $now) {//outside normal dates
				if ($now > $testsettings['startdate'] && $now<$testsettings['reviewdate']) {
					$isreview = true;
				} else {
					if (!$isteacher) {
						echo _('Assessment is closed');
						leavetestmsg();
						exit;
					}
				}
			}
		}
	   } else {
	     // SELECT#13
		$query = "SELECT startdate,enddate FROM imas_exceptions WHERE userid='{$sessiondata['actas']}' AND assessmentid='{$line['assessmentid']}'";
		$result2 = mysql_query($query) or die("Query failed : " . mysql_error());
		$row = mysql_fetch_row($result2);
		if ($row!=null) {
			$exceptionduedate = $row[1];
		}
	}

	//}
	$superdone = false;
	if ($isreview) {
		if (isset($_POST['isreview']) && $_POST['isreview']==0) {
			echo _('Due date has passed.  Submission rejected. ');
			leavetestmsg();
			exit;
		}
		//$testsettings['displaymethod'] = "SkipAround";
		$testsettings['testtype']="Practice";
		$testsettings['defattempts'] = 0;
		$testsettings['defpenalty'] = 0;
		$testsettings['showans'] = '0';
		
		$seeds = explode(",",$line['reviewseeds']);
		
		if (strpos($line['reviewscores'],';')===false) {
			$reviewscores = explode(",",$line['reviewscores']);
			$noraw = true;
			$reviewrawscores = $reviewscores;
		} else {
			$sp = explode(';',$line['reviewscores']);
			$reviewscores = explode(',', $sp[0]);
			$reviewrawscores = explode(',', $sp[1]);
			$noraw = false;
		}
		
		$attempts = explode(",",$line['reviewattempts']);
		$lastanswers = explode("~",$line['reviewlastanswers']);
		if (trim($line['reviewreattempting'])=='') {
			$reattempting = array();
		} else {
			$reattempting = explode(",",$line['reviewreattempting']);
		}
	} else if ($timelimitkickout) {
		$now = time();
		$timelimitremaining = $testsettings['timelimit']-($now - $starttime);
		//check if past timelimit
		if ($timelimitremaining<1 || isset($_GET['superdone'])) {
			$superdone = true;
			$_GET['done']=true;
		}
		//check for past time limit, with some leniency for javascript timing.
		//want to reject if javascript was bypassed
		if ($timelimitremaining < -1*max(0.05*$testsettings['timelimit'],5)) {
			echo _('Time limit has expired.  Submission rejected. ');
			leavetestmsg();
			exit;
		}
		
		
	}
	$qi = getquestioninfo($questions,$testsettings);
	
	//check for withdrawn
	for ($i=0; $i<count($questions); $i++) {
		if ($qi[$questions[$i]]['withdrawn']==1 && $qi[$questions[$i]]['points']>0) {
			$bestscores[$i] = $qi[$questions[$i]]['points'];
			$bestrawscores[$i] = 1;
		}
	}
	
	$allowregen = (!$superdone && ($testsettings['testtype']=="Practice" || $testsettings['testtype']=="Homework"));
	$showeachscore = ($testsettings['testtype']=="Practice" || $testsettings['testtype']=="AsGo" || $testsettings['testtype']=="Homework");
	$showansduring = (($testsettings['testtype']=="Practice" || $testsettings['testtype']=="Homework") && is_numeric($testsettings['showans']));
	$showansafterlast = ($testsettings['showans']==='F' || $testsettings['showans']==='J');
	$noindivscores = ($testsettings['testtype']=="EndScore" || $testsettings['testtype']=="NoScores");
	$reviewatend = ($testsettings['testtype']=="EndReview");
	$showhints = ($testsettings['showhints']==1);
	$showtips = $testsettings['showtips'];
	$regenonreattempt = (($testsettings['shuffle']&8)==8);
	
	$reloadqi = false;
	if (isset($_GET['reattempt'])) {
		if ($_GET['reattempt']=="all") {
			for ($i = 0; $i<count($questions); $i++) {
				if ($attempts[$i]<$qi[$questions[$i]]['attempts'] || $qi[$questions[$i]]['attempts']==0) {
					//$scores[$i] = -1;
					if ($noindivscores) { //clear scores if 
						$bestscores[$i] = -1;
						$bestrawscores[$i] = -1;
					}
					if (!in_array($i,$reattempting)) {
						$reattempting[] = $i;
					}
					if (($regenonreattempt && $qi[$questions[$i]]['regen']==0) || $qi[$questions[$i]]['regen']==1) {
						$seeds[$i] = rand(1,9999);
						if (isset($qi[$questions[$i]]['answeights'])) {
							$reloadqi = true;
						}
					}
				}
			}
		} else if ($_GET['reattempt']=="canimprove") {
			$remainingposs = getallremainingpossible($qi,$questions,$testsettings,$attempts);
			for ($i = 0; $i<count($questions); $i++) {
				if ($attempts[$i]<$qi[$questions[$i]]['attempts'] || $qi[$questions[$i]]['attempts']==0) {
					if ($noindivscores || getpts($scores[$i])<$remainingposs[$i]) {
						//$scores[$i] = -1;
						if (!in_array($i,$reattempting)) {
							$reattempting[] = $i;
						}
						if (($regenonreattempt && $qi[$questions[$i]]['regen']==0) || $qi[$questions[$i]]['regen']==1) {
							$seeds[$i] = rand(1,9999);
							if (isset($qi[$questions[$i]]['answeights'])) {
								$reloadqi = true;
							}
						}
					}
				}
			}
		} else {
			$toclear = $_GET['reattempt'];
			if ($attempts[$toclear]<$qi[$questions[$toclear]]['attempts'] || $qi[$questions[$toclear]]['attempts']==0) {
				//$scores[$toclear] = -1;
				if (!in_array($toclear,$reattempting)) {
					$reattempting[] = $toclear;
				}
				if (($regenonreattempt && $qi[$questions[$toclear]]['regen']==0) || $qi[$questions[$toclear]]['regen']==1) {
					$seeds[$toclear] = rand(1,9999);
					if (isset($qi[$questions[$toclear]]['answeights'])) {
						$reloadqi = true;
					}
				}
			}
		}
		recordtestdata();
	}
	if (isset($_GET['regen']) && $allowregen && $qi[$questions[$_GET['regen']]]['allowregen']==1) {
		srand();
		$toregen = $_GET['regen'];
		$seeds[$toregen] = rand(1,9999);
		$scores[$toregen] = -1;
		$rawscores[$toregen] = -1;
		$attempts[$toregen] = 0;
		$newla = array();
		deletefilesifnotused($lastanswers[$toregen],$bestlastanswers[$toregen]);
		$laarr = explode('##',$lastanswers[$toregen]);
		foreach ($laarr as $lael) {
			if ($lael=="ReGen") {
				$newla[] = "ReGen";
			}
		}
		$newla[] = "ReGen";
		$lastanswers[$toregen] = implode('##',$newla);
		$loc = array_search($toregen,$reattempting);
		if ($loc!==false) {
			array_splice($reattempting,$loc,1);
		}
		if (isset($qi[$questions[$toregen]]['answeights'])) {
			$reloadqi = true;
		}
		recordtestdata();
	}
	if (isset($_GET['regenall']) && $allowregen) {
		srand();
		if ($_GET['regenall']=="missed") {
			for ($i = 0; $i<count($questions); $i++) {
				if (getpts($scores[$i])<$qi[$questions[$i]]['points'] && $qi[$questions[$i]]['allowregen']==1) { 
					$scores[$i] = -1;
					$rawscores[$i] = -1;
					$attempts[$i] = 0;
					$seeds[$i] = rand(1,9999);
					$newla = array();
					deletefilesifnotused($lastanswers[$i],$bestlastanswers[$i]);
					$laarr = explode('##',$lastanswers[$i]);
					foreach ($laarr as $lael) {
						if ($lael=="ReGen") {
							$newla[] = "ReGen";
						}
					}
					$newla[] = "ReGen";
					$lastanswers[$i] = implode('##',$newla);
					$loc = array_search($i,$reattempting);
					if ($loc!==false) {
						array_splice($reattempting,$loc,1);
					}
					if (isset($qi[$questions[$i]]['answeights'])) {
						$reloadqi = true;
					}
				}
			}
		} else if ($_GET['regenall']=="all") {
			for ($i = 0; $i<count($questions); $i++) {
				if ($qi[$questions[$i]]['allowregen']==0) { 
					continue;
				}
				$scores[$i] = -1;
				$rawscores[$i] = -1;
				$attempts[$i] = 0;
				$seeds[$i] = rand(1,9999);
				$newla = array();
				deletefilesifnotused($lastanswers[$i],$bestlastanswers[$i]);
				$laarr = explode('##',$lastanswers[$i]);
				foreach ($laarr as $lael) {
					if ($lael=="ReGen") {
						$newla[] = "ReGen";
					}
				}
				$newla[] = "ReGen";
				$lastanswers[$i] = implode('##',$newla);
				$reattempting = array();
				if (isset($qi[$questions[$i]]['answeights'])) {
					$reloadqi = true;
				}
			}
		} else if ($_GET['regenall']=="fromscratch" && $testsettings['testtype']=="Practice" && !$isreview) {
			require_once("../includes/filehandler.php");
			//deleteasidfilesbyquery(array('userid'=>$userid,'assessmentid'=>$testsettings['id']),1);
			deleteasidfilesbyquery2('userid',$userid,$testsettings['id'],1);
			// DELETE#02
			$query = "DELETE FROM imas_assessment_sessions WHERE userid='$userid' AND assessmentid='{$testsettings['id']}' LIMIT 1";
			$result = mysql_query($query) or die("Query failed : $query: " . mysql_error());
			header('Location: ' . $urlmode  . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/showtest.php?cid={$testsettings['courseid']}&id={$testsettings['id']}");
			exit;	
		}
		
		recordtestdata();
			
	}
	if (isset($_GET['jumptoans']) && $testsettings['showans']==='J') {
		$tojump = $_GET['jumptoans'];
		$attempts[$tojump]=$qi[$questions[$tojump]]['attempts'];
		if ($scores[$tojump]<0){
			$scores[$tojump] = 0;
			$rawscores[$tojump] = 0;
		}
		recordtestdata();
		$reloadqi = true;
	}
	
	if ($reloadqi) {
		$qi = getquestioninfo($questions,$testsettings);
	}
	
	
	$isdiag = isset($sessiondata['isdiag']);
	if ($isdiag) {
		$diagid = $sessiondata['isdiag'];
	}
	$isltilimited = (isset($sessiondata['ltiitemtype']) && $sessiondata['ltiitemtype']==0 && $sessiondata['ltirole']=='learner');


	if (isset($CFG['GEN']['keeplastactionlog']) && isset($sessiondata['loginlog'.$testsettings['courseid']])) {
	  $now = time();
	  // UPDATE#04
		$query = "UPDATE imas_login_log SET lastaction=$now WHERE id=".$sessiondata['loginlog'.$testsettings['courseid']];
		mysql_query($query) or die("Query failed : " . mysql_error());
	}
		
	header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
	header("Expires: Mon, 26 Jul 1997 05:00:00 GMT"); // Date in the past
	$useeditor = 1;
if (!isset($_POST['embedpostback'])) {
	
	if ($testsettings['eqnhelper']==1 || $testsettings['eqnhelper']==2) {
		$placeinhead = '<script type="text/javascript">var eetype='.$testsettings['eqnhelper'].'</script>';
		$placeinhead .= "<script type=\"text/javascript\" src=\"$imasroot/javascript/eqnhelper.js?v=030112\"></script>";
		$placeinhead .= '<style type="text/css"> div.question input.btn { margin-left: 10px; } </style>';
		
	} else if ($testsettings['eqnhelper']==3 || $testsettings['eqnhelper']==4) {
		$placeinhead = "<link rel=\"stylesheet\" href=\"$imasroot/assessment/mathquill.css?v=102113\" type=\"text/css\" />";
		if (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE')!==false) {
			$placeinhead .= '<!--[if lte IE 7]><style style="text/css">
				.mathquill-editable.empty { width: 0.5em; }
				.mathquill-rendered-math .numerator.empty, .mathquill-rendered-math .empty { padding: 0 0.25em;}
				.mathquill-rendered-math sup { line-height: .8em; }
				.mathquill-rendered-math .numerator {float: left; padding: 0;}
				.mathquill-rendered-math .denominator { clear: both;width: auto;float: left;}
				</style><![endif]-->';
		}
		$placeinhead .= "<script type=\"text/javascript\" src=\"$imasroot/javascript/mathquill_min.js?v=102113\"></script>";
		$placeinhead .= "<script type=\"text/javascript\" src=\"$imasroot/javascript/mathquilled.js?v=102113\"></script>";
		$placeinhead .= "<script type=\"text/javascript\" src=\"$imasroot/javascript/AMtoMQ.js?v=102113\"></script>";
		$placeinhead .= '<style type="text/css"> div.question input.btn { margin-left: 10px; } </style>';
		
	} 
	$useeqnhelper = $testsettings['eqnhelper'];
	
	//IP: eqntips 
	if ($testsettings['showtips']==2) {
		$placeinhead .= "<script type=\"text/javascript\" src=\"$imasroot/javascript/eqntips.js?v=032810\"></script>";
	}
	$placeinhead .= '<script type="text/javascript">
	   function toggleintroshow(n) {
	      var link = document.getElementById("introtoggle"+n);
	      var content = document.getElementById("intropiece"+n);
	      if (link.innerHTML.match("Hide")) {
	      	   link.innerHTML = link.innerHTML.replace("Hide","Show");
		   content.style.display = "none";
	      } else {
	      	   link.innerHTML = link.innerHTML.replace("Show","Hide");
		   content.style.display = "block";
	      }
	     }
	     function togglemainintroshow(el) {
	     	if ($("#intro").hasClass("hidden")) {
	     		$(el).html("'._("Hide Intro/Instructions").'");
	     		$("#intro").removeClass("hidden").addClass("intro");
	     	} else {
	     		$("#intro").addClass("hidden");
	     		$(el).html("'._("Show Intro/Instructions").'");
	     	}
	     }
	     </script>';

	$cid = $testsettings['courseid'];
	if ($testsettings['displaymethod'] == "VideoCue") {
		//$placeinhead .= '<script src="'.$urlmode.'www.youtube.com/player_api"></script>';
		$placeinhead .= '<script src="'.$imasroot.'/javascript/ytapi.js"></script>';
	}
	require("header.php");
	if ($testsettings['noprint'] == 1) {
		echo '<style type="text/css" media="print"> div.question, div.todoquestion, div.inactive { display: none;} </style>';
	}
	if (!$isdiag && !$isltilimited && !$sessiondata['intreereader']) {
		if (isset($sessiondata['actas'])) {
			echo "<div class=breadcrumb>$breadcrumbbase <a href=\"../course/course.php?cid={$testsettings['courseid']}\">{$sessiondata['coursename']}</a> ";
			echo "&gt; <a href=\"../course/gb-viewasid.php?cid={$testsettings['courseid']}&amp;asid=$testid&amp;uid={$sessiondata['actas']}\">", _('Gradebook Detail'), "</a> ";
			echo "&gt; ", _('View as student'), "</div>";
		} else {
			echo "<div class=breadcrumb>";
			echo "<span style=\"float:right;\">$userfullname</span>";
			if (isset($sessiondata['ltiitemtype']) && $sessiondata['ltiitemtype']==0) {
				echo "$breadcrumbbase ", _('Assessment'), "</div>";
			} else {
				echo "$breadcrumbbase <a href=\"../course/course.php?cid={$testsettings['courseid']}\">{$sessiondata['coursename']}</a> ";
	 
				echo "&gt; ", _('Assessment'), "</div>";
			}
		}
	} else if ($isltilimited) {
		echo "<span style=\"float:right;\">";
		if ($testsettings['msgtoinstr']==1) {
		  // SELECT#14
			$query = "SELECT COUNT(id) FROM imas_msgs WHERE msgto='$userid' AND courseid='$cid' AND (isread=0 OR isread=4)";
			$result = mysql_query($query) or die("Query failed : " . mysql_error());
			$msgcnt = mysql_result($result,0,0);
			echo "<a href=\"$imasroot/msgs/msglist.php?cid=$cid\" onclick=\"return confirm('", _('This will discard any unsaved work.'), "');\">", _('Messages'), " ";
			if ($msgcnt>0) {
				echo '<span style="color:red;">('.$msgcnt.' new)</span>';
			} 
			echo '</a> ';
		}
		if ($testsettings['allowlate']==1 && $sessiondata['latepasses']>0 && !$isreview) {
			echo "<a href=\"$imasroot/course/redeemlatepass.php?cid=$cid&aid={$testsettings['id']}\" onclick=\"return confirm('", _('This will discard any unsaved work.'), "');\">", _('Redeem LatePass'), "</a> ";
		}
		
		
		if ($sessiondata['ltiitemid']==$testsettings['id'] && $isreview) {
			if ($testsettings['showans']!='N') {
				echo '<p><a href="../course/gb-viewasid.php?cid='.$cid.'&asid='.$testid.'">', _('View your scored assessment'), '</a></p>';
			}
		}
		echo '</span>';
	}
	
	if ((!$sessiondata['isteacher'] || isset($sessiondata['actas'])) && ($testsettings['isgroup']==1 || $testsettings['isgroup']==2) && ($sessiondata['groupid']==0 || isset($_GET['addgrpmem']))) {
		if (isset($_POST['grpsubmit'])) {
			if ($sessiondata['groupid']==0) {
				echo '<p>', _('Group error - lost group info'), '</p>';
			}
			$fieldstocopy = 'assessmentid,agroupid,questions,seeds,scores,attempts,lastanswers,starttime,endtime,bestseeds,bestattempts,bestscores,bestlastanswers,feedback,reviewseeds,reviewattempts,reviewscores,reviewlastanswers,reattempting,reviewreattempting';

			// SELECT#15
			$query = "SELECT $fieldstocopy FROM imas_assessment_sessions WHERE id='$testid'";
			$result = mysql_query($query) or die("Query failed : $query:" . mysql_error());
			$rowgrptest = mysql_fetch_row($result);
			$rowgrptest = addslashes_deep($rowgrptest);
			$insrow = "'".implode("','",$rowgrptest)."'";
			$loginfo = "$userfullname creating group. ";
			for ($i=1;$i<$testsettings['groupmax'];$i++) {
			  if (isset($_POST['user'.$i]) && $_POST['user'.$i]!=0) {
			    // SELECT#16
					$query = "SELECT password,LastName,FirstName FROM imas_users WHERE id='{$_POST['user'.$i]}'";
					$result = mysql_query($query) or die("Query failed : $query:" . mysql_error());
					$thisusername = mysql_result($result,0,2) . ' ' . mysql_result($result,0,1);	
					if ($testsettings['isgroup']==1) {
						$md5pw = md5($_POST['pw'.$i]);
						if (mysql_result($result,0,0)!=$md5pw) {
							echo "<p>$thisusername: ", _('password incorrect'), "</p>";
							$errcnt++;
							continue;
						} 
					} 
						
					$thisuser = $_POST['user'.$i];
					// SELECT#17
					$query = "SELECT id,agroupid FROM imas_assessment_sessions WHERE userid='{$_POST['user'.$i]}' AND assessmentid={$testsettings['id']} ORDER BY id LIMIT 1";
					$result = mysql_query($query) or die("Query failed : $query:" . mysql_error());
					if (mysql_num_rows($result)>0) {      
						$row = mysql_fetch_row($result);
						if ($row[1]>0) { 
							echo "<p>", sprintf(_('%s already has a group.  No change made'), $thisusername), "</p>";
							$loginfo .= "$thisusername already in group. ";
						} else {
						  // INSERT#08
							$query = "INSERT INTO imas_stugroupmembers (userid,stugroupid) VALUES ('$userid','{$sessiondata['groupid']}')";
							mysql_query($query) or die("Query failed : $query:" . mysql_error());
							
							$fieldstocopy = explode(',',$fieldstocopy);
							$sets = array();
							foreach ($fieldstocopy as $k=>$val) {
								$sets[] = "$val='{$rowgrptest[$k]}'";
							}
							$setslist = implode(',',$sets);
							// UPDATE#05
							$query = "UPDATE imas_assessment_sessions SET $setslist WHERE id='{$row[0]}'";
							
							//$query = "UPDATE imas_assessment_sessions SET assessmentid='{$rowgrptest[0]}',agroupid='{$rowgrptest[1]}',questions='{$rowgrptest[2]}'";
							//$query .= ",seeds='{$rowgrptest[3]}',scores='{$rowgrptest[4]}',attempts='{$rowgrptest[5]}',lastanswers='{$rowgrptest[6]}',";
							//$query .= "starttime='{$rowgrptest[7]}',endtime='{$rowgrptest[8]}',bestseeds='{$rowgrptest[9]}',bestattempts='{$rowgrptest[10]}',";
							//$query .= "bestscores='{$rowgrptest[11]}',bestlastanswers='{$rowgrptest[12]}'  WHERE id='{$row[0]}'";
							//$query = "UPDATE imas_assessment_sessions SET agroupid='$agroupid' WHERE id='{$row[0]}'";
							mysql_query($query) or die("Query failed : $query:" . mysql_error());
							echo "<p>", sprintf(_('%s added to group, overwriting existing attempt.'), $thisusername), "</p>";
							$loginfo .= "$thisusername switched to group. ";
						}
					} else {
					  // INSERT#09
						$query = "INSERT INTO imas_stugroupmembers (userid,stugroupid) VALUES ('{$_POST['user'.$i]}','{$sessiondata['groupid']}')";
						mysql_query($query) or die("Query failed : $query:" . mysql_error());

						// INSERT#10
						$query = "INSERT INTO imas_assessment_sessions (userid,$fieldstocopy) ";
						$query .= "VALUES ('{$_POST['user'.$i]}',$insrow)";
						mysql_query($query) or die("Query failed : $query:" . mysql_error());
						echo "<p>", sprintf(_('%s added to group.'), $thisusername), "</p>";
						$loginfo .= "$thisusername added to group. ";
					}
				}
			}
			$now = time();
			if (isset($GLOBALS['CFG']['log'])) {
			  // INSERT#11
				$query = "INSERT INTO imas_log (time,log) VALUES ($now,'".addslashes($loginfo)."')";
				mysql_query($query) or die("Query failed : " . mysql_error());
			}
		} else {
			echo '<div id="headershowtest" class="pagetitle"><h2>', _('Select group members'), '</h2></div>';
			if ($sessiondata['groupid']==0) {
			  //a group should already exist
			  // SELECT#18
				$query = 'SELECT i_sg.id FROM imas_stugroups as i_sg JOIN imas_stugroupmembers as i_sgm ON i_sg.id=i_sgm.stugroupid ';
				$query .= "WHERE i_sgm.userid='$userid' AND i_sg.groupsetid={$testsettings['groupsetid']}";
				$result = mysql_query($query) or die("Query failed : " . mysql_error());
				if (mysql_num_rows($result)==0) {
					echo '<p>', _('Group error.  Please try reaccessing the assessment from the course page'), '</p>';
				}
				$agroupid = mysql_result($result,0,0);
				$sessiondata['groupid'] = $agroupid;
				writesessiondata();
			} else {
				$agroupid = $sessiondata['groupid'];
			}
			
			
			echo _('Current Group Members:'), " <ul>";
			$curgrp = array();
			// SELECT#19
			$query = "SELECT imas_users.id,imas_users.FirstName,imas_users.LastName FROM imas_users,imas_stugroupmembers WHERE ";
			$query .= "imas_users.id=imas_stugroupmembers.userid AND imas_stugroupmembers.stugroupid='{$sessiondata['groupid']}' ORDER BY imas_users.LastName,imas_users.FirstName";
			$result = mysql_query($query) or die("Query failed : $query;  " . mysql_error());
			while ($row = mysql_fetch_row($result)) {
				$curgrp[0] = $row[0];
				echo "<li>{$row[2]}, {$row[1]}</li>";
			}
			echo "</ul>";	
			
			$curinagrp = array();
			// SELECT#20
			$query = 'SELECT i_sgm.userid FROM imas_stugroups as i_sg JOIN imas_stugroupmembers as i_sgm ON i_sg.id=i_sgm.stugroupid ';
			$query .= "WHERE i_sg.groupsetid={$testsettings['groupsetid']}";
			$result = mysql_query($query) or die("Query failed : $query;  " . mysql_error());
			while ($row = mysql_fetch_row($result)) {
				$curinagrp[] = $row[0];
			}
			$curids = implode(',',$curinagrp);
			$selops = '<option value="0">' . _('Select a name..') . '</option>';

			// SELECT#21
			$query = "SELECT imas_users.id,imas_users.FirstName,imas_users.LastName FROM imas_users,imas_students ";
			$query .= "WHERE imas_users.id=imas_students.userid AND imas_students.courseid='{$testsettings['courseid']}' ";
			$query .= "AND imas_users.id NOT IN ($curids) ORDER BY imas_users.LastName,imas_users.FirstName";
			$result = mysql_query($query) or die("Query failed : $query;  " . mysql_error());
			while ($row = mysql_fetch_row($result)) {
				$selops .= "<option value=\"{$row[0]}\">{$row[2]}, {$row[1]}</option>";
			}
			//TODO i18n 
			echo '<p>Each group member (other than the currently logged in student) to be added should select their name ';
			if ($testsettings['isgroup']==1) {
				echo 'and enter their password ';
			}
			echo 'here.</p>';
			echo '<form method="post" enctype="multipart/form-data" action="showtest.php?addgrpmem=true">';
			echo "<input type=\"hidden\" name=\"asidverify\" value=\"$testid\" />";
			echo '<input type="hidden" name="disptime" value="'.time().'" />';
			echo "<input type=\"hidden\" name=\"isreview\" value=\"". ($isreview?1:0) ."\" />";
			for ($i=1;$i<$testsettings['groupmax']-count($curgrp)+1;$i++) {
				echo '<br />', _('Username'), ': <select name="user'.$i.'">'.$selops.'</select> ';
				if ($testsettings['isgroup']==1) {
					echo _('Password'), ': <input type="password" name="pw'.$i.'" />'."\n";
				}
			}
			echo '<p><input type=submit name="grpsubmit" value="', _('Record Group and Continue'), '"/></p>';
			echo '</form>';
			require("../footer.php");
			exit;
		}
	}
	/*
	no need to do anything in this case
	if ((!$sessiondata['isteacher'] || isset($sessiondata['actas'])) && $testsettings['isgroup']==3  && $sessiondata['groupid']==0) {
		//double check not already added to group by someone else
		// SELECT#22
		$query = "SELECT agroupid FROM imas_assessment_sessions WHERE id='$testid'";
		$result = mysql_query($query) or die("Query failed : $query:" . mysql_error());
		$agroupid = mysql_result($result,0,0);
		if ($agroupid==0) { //really has no group, create group
		// UPDATE#06
			$query = "UPDATE imas_assessment_sessions SET agroupid='$testid' WHERE id='$testid'";
			mysql_query($query) or die("Query failed : $query:" . mysql_error());
			$agroupid = $testid;
		} else {
			echo "<p>Someone already added you to a group.  Using that group.</p>";
		}
		$sessiondata['groupid'] = $agroupid;
		writesessiondata();
	}
	*/
	
	//if was added to existing group, need to reload $questions, etc
	echo '<div id="headershowtest" class="pagetitle">';
	echo "<h2>{$testsettings['name']}</h2></div>\n";
	if (isset($sessiondata['actas'])) {
	  echo '<p style="color: red;">', _('Teacher Acting as ');
	  // SELECT#23
		$query = "SELECT LastName, FirstName FROM imas_users WHERE id='{$sessiondata['actas']}'";
		$result = mysql_query($query) or die("Query failed : $query:" . mysql_error());
		$row = mysql_fetch_row($result);
		echo $row[1].' '.$row[0];
		echo '<p>';
	}
	
	if ($testsettings['testtype']=="Practice" && !$isreview) {
		echo "<div class=right><span style=\"color:#f00\">Practice Test.</span>  <a href=\"showtest.php?regenall=fromscratch\">", _('Create new version.'), "</a></div>";
	}
	if (!$isreview && !$superdone) {
		if ($exceptionduedate > 0) {
			$timebeforedue = $exceptionduedate - time();
		} else {
			$timebeforedue = $testsettings['enddate'] - time();
		}
		if ($timebeforedue < 0) {
			$duetimenote = _('Past due');
		} else if ($timebeforedue < 24*3600) { //due within 24 hours
			if ($timebeforedue < 300) {
				$duetimenote = '<span style="color:#f00;">' . _('Due in under ');
			} else {
				$duetimenote = '<span>' . _('Due in ');
			}
			if ($timebeforedue>3599) {
				$duetimenote .= floor($timebeforedue/3600). " " . _('hours') . ", ";
			}
			$duetimenote .= ceil(($timebeforedue%3600)/60). " " . _('minutes') . "</span>";
		} else {
			if ($testsettings['enddate']==2000000000) {
				$duetimenote = '';
			} else if ($exceptionduedate > 0) {
				$duetimenote = _('Due') . " " . tzdate('D m/d/Y g:i a',$exceptionduedate);
			} else {
				$duetimenote = _('Due') . " " . tzdate('D m/d/Y g:i a',$testsettings['enddate']);
			}
		}
	}
	$restrictedtimelimit = false;
	if ($testsettings['timelimit']>0 && !$isreview && !$superdone) {
		$now = time();
		$totremaining = $testsettings['timelimit']-($now - $starttime);
		$remaining = $totremaining;
		if ($timebeforedue < $remaining) {
			$remaining = $timebeforedue - 5;	
			$restrictedtimelimit = true;
		}
		if ($testsettings['timelimit']>3600) {
			$tlhrs = floor($testsettings['timelimit']/3600);
			$tlrem = $testsettings['timelimit'] % 3600;
			$tlmin = floor($tlrem/60);
			$tlsec = $tlrem % 60;
			$tlwrds = "$tlhrs " . _('hour');
			if ($tlhrs > 1) { $tlwrds .= "s";}
			if ($tlmin > 0) { $tlwrds .= ", $tlmin " . _('minute');}
			if ($tlmin > 1) { $tlwrds .= "s";}
			if ($tlsec > 0) { $tlwrds .= ", $tlsec " . _('second');}
			if ($tlsec > 1) { $tlwrds .= "s";}
		} else if ($testsettings['timelimit']>60) {
			$tlmin = floor($testsettings['timelimit']/60);
			$tlsec = $testsettings['timelimit'] % 60;
			$tlwrds = "$tlmin " . _('minute');
			if ($tlmin > 1) { $tlwrds .= "s";}
			if ($tlsec > 0) { $tlwrds .= ", $tlsec " . _('second');}
			if ($tlsec > 1) { $tlwrds .= "s";}
		} else {
			$tlwrds = $testsettings['timelimit'] . " " . _('second(s)');
		}
		if ($remaining < 0) {
			echo "<div class=right>", sprintf(_('Timelimit: %s.  Time Expired'), $tlwrds), "</div>\n";
		} else {
		if ($remaining > 3600) {
			$hours = floor($remaining/3600);
			$remaining = $remaining - 3600*$hours;
		} else { $hours = 0;}
		if ($remaining > 60) {
			$minutes = floor($remaining/60);
			$remaining = $remaining - 60*$minutes;
		} else {$minutes=0;}
		$seconds = $remaining;
		echo "<div class=right id=timelimitholder><span id=\"timercontent\">", _('Timelimit'), ": $tlwrds. ";
		if (!isset($_GET['action']) && $restrictedtimelimit) {
			echo '<span style="color:#0a0;">', _('Time limit shortened because of due date'), '</span> ';
		}
		echo "<span id=\"timerwrap\"><span id=timeremaining ";
		if ($totremaining<300) {
			echo 'style="color:#f00;" ';
		}
		echo ">$hours:$minutes:$seconds</span> ", _('remaining'), ".</span></span> <span onclick=\"toggletimer()\" style=\"color:#aaa;\" class=\"clickable\" id=\"timerhide\" title=\"",_('Hide'),"\">[x]</span></div>\n";
		echo "<script type=\"text/javascript\">\n";
		echo " hours = $hours; minutes = $minutes; seconds = $seconds; done=false;\n";	
		echo " function updatetime() {\n";
		echo "	  seconds--;\n";
		echo "    if (seconds==0 && minutes==0 && hours==0) {done=true; ";
		if ($timelimitkickout) {
			echo "		document.getElementById('timelimitholder').className = \"\";";
			echo "		document.getElementById('timelimitholder').style.color = \"#f00\";";
			echo "		document.getElementById('timelimitholder').innerHTML = \"", _('Time limit expired - submitting now'), "\";";
			echo " 		document.getElementById('timelimitholder').style.fontSize=\"300%\";";
			echo "		if (document.getElementById(\"qform\") == null) { ";
			echo "			setTimeout(\"window.location.pathname='$imasroot/assessment/showtest.php?action=skip&superdone=true'\",2000); return;";
			echo "		} else {";
			echo "		var theform = document.getElementById(\"qform\");";
			echo " 		var action = theform.getAttribute(\"action\");";
			echo "		theform.setAttribute(\"action\",action+'&superdone=true');";
			echo "		if (doonsubmit(theform,true,true)) { setTimeout('document.getElementById(\"qform\").submit()',2000);}} \n";
			echo "		return 0;";
			echo "      }";
			
		} else {
			echo "		alert(\"", _('Time Limit has elapsed'), "\");}\n";
		}
		echo "    if (seconds==0 && minutes==5 && hours==0) {document.getElementById('timeremaining').style.color=\"#f00\";}\n";
		echo "    if (seconds==5 && minutes==0 && hours==0) {document.getElementById('timeremaining').style.fontSize=\"150%\";}\n";
		echo "    if (seconds < 0) { seconds=59; minutes--; }\n";
		echo "    if (minutes < 0) { minutes=59; hours--;}\n";
		echo "	  str = '';\n";
		echo "	  if (hours > 0) { str += hours + ':';}\n";
		echo "    if (hours > 0 && minutes <10) { str += '0';}\n";
		echo "	  if (minutes >0) {str += minutes + ':';}\n";
		echo "	    else if (hours>0) {str += '0:';}\n";
		echo "      else {str += ':';}\n";
		echo "    if (seconds<10) { str += '0';}\n";
		echo "	  str += seconds + '';\n";
		echo "	  document.getElementById('timeremaining').innerHTML = str;\n";
		echo "    if (!done) {setTimeout(\"updatetime()\",1000);}\n";
		echo " }\n";
		echo " updatetime();\n";
		echo ' $(document).ready(function() {
			var s = $("#timerwrap");
			var pos = s.position();                   
			$(window).scroll(function() {
			   var windowpos = $(window).scrollTop();
			   if (windowpos >= pos.top) {
			     s.addClass("sticky");
			   } else {
			     s.removeClass("sticky");
			   }
			   });
			   });';
		echo ' function toggletimer() {
				if ($("#timerhide").text()=="[x]") {
					$("#timercontent").hide();
					$("#timerhide").text("['._("Show Timer").']");
					$("#timerhide").attr("title","'._("Show Timer").'");
				} else {
					$("#timercontent").show();
					$("#timerhide").text("[x]");
					$("#timerhide").attr("title","'._("Hide").'");
				}
			}';
		echo "</script>\n";
		}
	} else if ($isreview) {
		echo "<div class=right style=\"color:#f00;clear:right;\">In Review Mode - no scores will be saved<br/><a href=\"showtest.php?regenall=all\">", _('Create new versions of all questions.'), "</a></div>\n";	
	} else if ($superdone) {
		echo "<div class=right>", _('Time limit expired'), "</div>";
	} else {
		echo "<div class=right>$duetimenote</div>\n";
		//if ($timebeforedue < 2*3600 && $timebeforedue > 300 ) {
		//	echo '<script type="text/javascript">var duetimewarning = setTimeout(function() {alert("This assignment is due in about 5 minutes");},'.(1000*($timebeforedue-300)).');</script>';
		//}
	}
} else {
	require_once("../filter/filter.php");
}
	//identify question-specific  intro/instruction 
	//comes in format [Q 1-3] in intro
	if (strpos($testsettings['intro'],'[Q')!==false) {
		$testsettings['intro'] = preg_replace('/((<span|<strong|<em)[^>]*>)?\[Q\s+(\d+(\-(\d+))?)\s*\]((<\/span|<\/strong|<\/em)[^>]*>)?/','[Q $3]',$testsettings['intro']);
		if(preg_match_all('/\<p[^>]*>\s*\[Q\s+(\d+)(\-(\d+))?\s*\]\s*<\/p>/',$testsettings['intro'],$introdividers,PREG_SET_ORDER)) {
			$intropieces = preg_split('/\<p[^>]*>\s*\[Q\s+(\d+)(\-(\d+))?\s*\]\s*<\/p>/',$testsettings['intro']);
			foreach ($introdividers as $k=>$v) {
				if (count($v)==4) {
					$introdividers[$k][2] = $v[3];
				} else if (count($v)==2) {
					$introdividers[$k][2] = $v[1];
				}
			}
			$testsettings['intro'] = array_shift($intropieces);
		}
	}
	if (isset($_GET['action'])) {
		if ($_GET['action']=="skip" || $_GET['action']=="seq") {
			echo '<div class="right"><a href="#" onclick="togglemainintroshow(this);return false;">'._("Show Intro/Instructions").'</a></div>';
			//echo "<div class=right><span onclick=\"document.getElementById('intro').className='intro';\"><a href=\"#\">", _('Show Instructions'), "</a></span></div>\n";
		}
		if ($_GET['action']=="scoreall") {
			//score test
			$GLOBALS['scoremessages'] = '';
			for ($i=0; $i < count($questions); $i++) {
				//if (isset($_POST["qn$i"]) || isset($_POST['qn'.(1000*($i+1))]) || isset($_POST["qn$i-0"]) || isset($_POST['qn'.(1000*($i+1)).'-0'])) {
					if ($_POST['verattempts'][$i]!=$attempts[$i]) {
						echo sprintf(_('Question %d has been submitted since you viewed it.  Your answer just submitted was not scored or recorded.'), ($i+1)), "<br/>";
					} else {
						scorequestion($i,false);
					}
				//}
			}
			//record scores
			
			$now = time();
			if (isset($_POST['disptime']) && !$isreview) {
				$used = $now - intval($_POST['disptime']);
				$timesontask[0] .= (($timesontask[0]=='')?'':'~').$used;
			}	
					
			if (isset($_POST['saveforlater'])) {
				recordtestdata(true);
				if ($GLOBALS['scoremessages'] != '') {
					echo '<p>'.$GLOBALS['scoremessages'].'</p>';
				}
				echo "<p>", _('Answers saved, but not submitted for grading.  You may continue with the test, or come back to it later. ');
				if ($testsettings['timelimit']>0) {echo _('The timelimit will continue to count down');}
				echo "</p><p>", _('<a href="showtest.php">Return to test</a> or'), ' ';
				leavetestmsg();
				
			} else {
				recordtestdata();
				if ($GLOBALS['scoremessages'] != '') {
					echo '<p>'.$GLOBALS['scoremessages'].'</p>';
				}
				$shown = showscores($questions,$attempts,$testsettings);
			
				endtest($testsettings);
				if ($shown) {leavetestmsg();}
			}
		} else if ($_GET['action']=="shownext") {
			if (isset($_GET['score'])) {
				$last = $_GET['score'];
				
				if ($_POST['verattempts']!=$attempts[$last]) {
					echo "<p>", _('The last question has been submittted since you viewed it, and that grade is shown below.  Your answer just submitted was not scored or recorded.'), "</p>";
				} else {
					if (isset($_POST['disptime']) && !$isreview) {
						$used = $now - intval($_POST['disptime']);
						$timesontask[$last] .= (($timesontask[$last]=='')?'':'~').$used;
					}
					$GLOBALS['scoremessages'] = '';
					$rawscore = scorequestion($last);
					if ($GLOBALS['scoremessages'] != '') {
						echo '<p>'.$GLOBALS['scoremessages'].'</p>';
					}
					//record score
					
					recordtestdata();
				}
				if ($showeachscore) {
					$possible = $qi[$questions[$last]]['points'];
					echo "<p>", _('Previous Question'), ":<br/>";
					if (getpts($rawscore)!=getpts($scores[$last])) {
						echo "<p>", _('Score before penalty on last attempt: ');
						echo printscore($rawscore,$last);
						echo "</p>";
					}
					echo _('Score on last attempt'), ": ";
					echo printscore($scores[$last],$last);
					echo "<br/>", _('Score in gradebook'), ": ";
					echo printscore($bestscores[$last],$last);
					 
					echo "</p>\n";
					if (hasreattempts($last)) {
						echo "<p><a href=\"showtest.php?action=shownext&to=$last&amp;reattempt=$last\">", _('Reattempt last question'), "</a>.  ", _('If you do not reattempt now, you will have another chance once you complete the test.'), "</p>\n";
					}
				}
				if ($allowregen && $qi[$questions[$last]]['allowregen']==1) {
					echo "<p><a href=\"showtest.php?action=shownext&to=$last&amp;regen=$last\">", _('Try another similar question'), "</a></p>\n";
				}
				//show next
				unset($toshow);
				for ($i=$last+1;$i<count($questions);$i++) {
					if (unans($scores[$i]) || amreattempting($i)) {
						$toshow=$i;
						$done = false;
						break;
					}
				}
				if (!isset($toshow)) { //no more to show
					$done = true;
				} 
			} else if (isset($_GET['to'])) {
				$toshow = addslashes($_GET['to']);
				$done = false;
			}
			
			if (!$done) { //can show next
				echo "<form id=\"qform\" method=\"post\" enctype=\"multipart/form-data\" action=\"showtest.php?action=shownext&amp;score=$toshow\" onsubmit=\"return doonsubmit(this)\">\n";
				echo "<input type=\"hidden\" name=\"asidverify\" value=\"$testid\" />";
				echo '<input type="hidden" name="disptime" value="'.time().'" />';
				echo "<input type=\"hidden\" name=\"isreview\" value=\"". ($isreview?1:0) ."\" />";
				basicshowq($toshow);
				showqinfobar($toshow,true,true);
				echo '<input type="submit" class="btn" value="', _('Continue'), '" />';
			} else { //are all done
				$shown = showscores($questions,$attempts,$testsettings);
				endtest($testsettings);
				if ($shown) {leavetestmsg();}
			}
		} else if ($_GET['action']=="skip") {

			if (isset($_GET['score'])) { //score a problem
				$qn = $_GET['score'];
				
				if ($_POST['verattempts']!=$attempts[$qn]) {
					echo "<p>", _('This question has been submittted since you viewed it, and that grade is shown below.  Your answer just submitted was not scored or recorded.'), "</p>";
				} else {
					if (isset($_POST['disptime']) && !$isreview) {
						$used = $now - intval($_POST['disptime']);
						$timesontask[$qn] .= (($timesontask[$qn]=='')?'':'~').$used;
					}
					$GLOBALS['scoremessages'] = '';
					$GLOBALS['questionmanualgrade'] = false;
					$rawscore = scorequestion($qn);
					
					$immediatereattempt = false;
					if (!$superdone && $showeachscore && hasreattempts($qn)) {
						if (!(($regenonreattempt && $qi[$questions[$toclear]]['regen']==0) || $qi[$questions[$toclear]]['regen']==1)) {
							if (!in_array($qn,$reattempting)) {
								//$reattempting[] = $qn;
								$immediatereattempt = true;
							}
						}
					}
					//record score
					recordtestdata();
				}
			   if (!$superdone) {
				echo filter("<div id=intro class=hidden>{$testsettings['intro']}</div>\n");
				$lefttodo = shownavbar($questions,$scores,$qn,$testsettings['showcat']);
				
				echo "<div class=inset>\n";
				echo "<a name=\"beginquestions\"></a>\n";
				if ($GLOBALS['scoremessages'] != '') {
					echo '<p>'.$GLOBALS['scoremessages'].'</p>';
				}
				
				if ($showeachscore) {
					$possible = $qi[$questions[$qn]]['points'];
					if (getpts($rawscore)!=getpts($scores[$qn])) {
						echo "<p>", _('Score before penalty on last attempt: ');
						echo printscore($rawscore,$qn);
						echo "</p>";
					}
					echo "<p>";
					echo _('Score on last attempt: ');
					echo printscore($scores[$qn],$qn);
					echo "</p>\n";
					echo "<p>", _('Score in gradebook: ');
					echo printscore($bestscores[$qn],$qn);
					echo "</p>";
					if ($GLOBALS['questionmanualgrade'] == true) {
						echo '<p><strong>', _('Note:'), '</strong> ', _('This question contains parts that can not be auto-graded.  Those parts will count as a score of 0 until they are graded by your instructor'), '</p>';
					}
										
					
				} else {
					echo '<p>'._('Question Scored').'</p>';
				}
				
				$reattemptsremain = false;
				if (hasreattempts($qn)) {
					$reattemptsremain = true;
				}
				
				if ($allowregen && $qi[$questions[$qn]]['allowregen']==1) {
					echo '<p>';
					if ($reattemptsremain && !$immediatereattempt) {
						echo "<a href=\"showtest.php?action=skip&amp;to=$qn&amp;reattempt=$qn\">", _('Reattempt last question'), "</a>, ";
					}
					echo "<a href=\"showtest.php?action=skip&amp;to=$qn&amp;regen=$qn\">", _('Try another similar question'), "</a>";
					if ($immediatereattempt) {
						echo _(", reattempt last question below, or select another question.");
					} else {
						echo _(", or select another question");
					}
					echo "</p>\n";
				} else if ($reattemptsremain && !$immediatereattempt) {
					echo "<p><a href=\"showtest.php?action=skip&amp;to=$qn&amp;reattempt=$qn\">", _('Reattempt last question'), "</a>";
					if ($lefttodo > 0) {
						echo  _(", or select another question");
					}
					echo '</p>';
				} else if ($lefttodo > 0) {
					echo "<p>"._('Select another question').'</p>';
				}
				
				if ($reattemptsremain == false && $showeachscore && $testsettings['showans']!='N') {
					//TODO i18n
					
					echo "<p>This question, with your last answer";
					if (($showansafterlast && $qi[$questions[$qn]]['showans']=='0') || $qi[$questions[$qn]]['showans']=='F' || $qi[$questions[$qn]]['showans']=='J') {
						echo " and correct answer";
						$showcorrectnow = true;
					} else if ($showansduring && $qi[$questions[$qn]]['showans']=='0' && $qi[$questions[$qn]]['showans']=='0' && $testsettings['showans']==$attempts[$qn]) {
						echo " and correct answer";
						$showcorrectnow = true;
					} else {
						$showcorrectnow = false;
					}
					
					echo ', is displayed below</p>';
					if (!$noraw && $showeachscore && $GLOBALS['questionmanualgrade'] != true) {
						//$colors = scorestocolors($rawscores[$qn], '', $qi[$questions[$qn]]['answeights'], $noraw);
						if (strpos($rawscores[$qn],'~')!==false) {
							$colors = explode('~',$rawscores[$qn]);
						} else {
							$colors = array($rawscores[$qn]);
						}
					} else {
						$colors = array();
					}
					if ($showcorrectnow) {
						displayq($qn,$qi[$questions[$qn]]['questionsetid'],$seeds[$qn],2,false,$attempts[$qn],false,false,false,$colors);
					} else {
						displayq($qn,$qi[$questions[$qn]]['questionsetid'],$seeds[$qn],false,false,$attempts[$qn],false,false,false,$colors);
					}
					
				} else if ($immediatereattempt) {
					$next = $qn;
					if (isset($intropieces)) {
						foreach ($introdividers as $k=>$v) {
							if ($v[1]<=$next+1 && $next+1<=$v[2]) {//right divider
								if ($next+1==$v[1]) {
									echo '<div><a href="#" id="introtoggle'.$k.'" onclick="toggleintroshow('.$k.'); return false;">', _('Hide Question Information'), '</a></div>';
									echo '<div class="intro" id="intropiece'.$k.'">'.filter($intropieces[$k]).'</div>';										
								} else {
									echo '<div><a href="#" id="introtoggle'.$k.'" onclick="toggleintroshow('.$k.'); return false;">', _('Show Question Information'), '</a></div>';
									echo '<div class="intro" style="display:none;" id="intropiece'.$k.'">'.filter($intropieces[$k]).'</div>';	
								}
								break;
							}
						}
					}
					echo "<form id=\"qform\" method=\"post\" enctype=\"multipart/form-data\" action=\"showtest.php?action=skip&amp;score=$next\" onsubmit=\"return doonsubmit(this)\">\n";
					echo "<input type=\"hidden\" name=\"asidverify\" value=\"$testid\" />";
					echo '<input type="hidden" name="disptime" value="'.time().'" />';
					echo "<input type=\"hidden\" name=\"isreview\" value=\"". ($isreview?1:0) ."\" />";
					echo "<a name=\"beginquestions\"></a>\n";
					basicshowq($next);
					showqinfobar($next,true,true);
					echo '<input type="submit" class="btn" value="Submit" />';
					if (($testsettings['showans']=='J' && $qi[$questions[$next]]['showans']=='0') || $qi[$questions[$next]]['showans']=='J') {
						echo ' <input type="button" class="btn" value="', _('Jump to Answer'), '" onclick="if (confirm(\'', _('If you jump to the answer, you must generate a new version to earn credit'), '\')) {window.location = \'showtest.php?action=skip&amp;jumptoans='.$next.'&amp;to='.$next.'\'}"/>';
					}
					echo "</form>\n";
					
				}
				
				echo "<br/><p>When you are done, <a href=\"showtest.php?action=skip&amp;done=true\">click here to see a summary of your scores</a>.</p>\n";
				
				echo "</div>\n";
			    }
			} else if (isset($_GET['to'])) { //jump to a problem
				$next = $_GET['to'];
				echo filter("<div id=intro class=hidden>{$testsettings['intro']}</div>\n");
				
				$lefttodo = shownavbar($questions,$scores,$next,$testsettings['showcat']);
				if (unans($scores[$next]) || amreattempting($next)) {
					echo "<div class=inset>\n";
					if (isset($intropieces)) {
						foreach ($introdividers as $k=>$v) {
							if ($v[1]<=$next+1 && $next+1<=$v[2]) {//right divider
								if ($next+1==$v[1]) {
									echo '<div><a href="#" id="introtoggle'.$k.'" onclick="toggleintroshow('.$k.'); return false;">', _('Hide Question Information'), '</a></div>';
									echo '<div class="intro" id="intropiece'.$k.'">'.filter($intropieces[$k]).'</div>';										
								} else {
									echo '<div><a href="#" id="introtoggle'.$k.'" onclick="toggleintroshow('.$k.'); return false;">', _('Show Question Information'), '</a></div>';
									echo '<div class="intro" style="display:none;" id="intropiece'.$k.'">'.filter($intropieces[$k]).'</div>';	
								}
								break;
							}
						}
					}
					echo "<form id=\"qform\" method=\"post\" enctype=\"multipart/form-data\" action=\"showtest.php?action=skip&amp;score=$next\" onsubmit=\"return doonsubmit(this)\">\n";
					echo "<input type=\"hidden\" name=\"asidverify\" value=\"$testid\" />";
					echo '<input type="hidden" name="disptime" value="'.time().'" />';
					echo "<input type=\"hidden\" name=\"isreview\" value=\"". ($isreview?1:0) ."\" />";
					echo "<a name=\"beginquestions\"></a>\n";
					basicshowq($next);
					showqinfobar($next,true,true);
					echo '<input type="submit" class="btn" value="Submit" />';
					if (($testsettings['showans']=='J' && $qi[$questions[$next]]['showans']=='0') || $qi[$questions[$next]]['showans']=='J') {
						echo ' <input type="button" class="btn" value="', _('Jump to Answer'), '" onclick="if (confirm(\'', _('If you jump to the answer, you must generate a new version to earn credit'), '\')) {window.location = \'showtest.php?action=skip&amp;jumptoans='.$next.'&amp;to='.$next.'\'}"/>';
					}
					echo "</form>\n";
					echo "</div>\n";
				} else {
					echo "<div class=inset>\n";
					echo "<a name=\"beginquestions\"></a>\n";
					echo _("You've already done this problem."), "\n";
					$reattemptsremain = false;
					if ($showeachscore) {
						$possible = $qi[$questions[$next]]['points'];
						echo "<p>", _('Score on last attempt: ');
						echo printscore($scores[$next],$next);
						echo "</p>\n";
						echo "<p>", _('Score in gradebook: ');
						echo printscore($bestscores[$next],$next);
						echo "</p>";
					}
					if (hasreattempts($next)) {
						//if ($showeachscore) {
							echo "<p><a href=\"showtest.php?action=skip&amp;to=$next&amp;reattempt=$next\">", _('Reattempt this question'), "</a></p>\n";
						//}
						$reattemptsremain = true;
					}
					if ($allowregen && $qi[$questions[$next]]['allowregen']==1) {
						echo "<p><a href=\"showtest.php?action=skip&amp;to=$next&amp;regen=$next\">", _('Try another similar question'), "</a></p>\n";
					}
					if ($lefttodo == 0) {
						echo "<a href=\"showtest.php?action=skip&amp;done=true\">", _('When you are done, click here to see a summary of your score'), "</a>\n";
					}
					if (!$reattemptsremain && $testsettings['showans']!='N') {// && $showeachscore) {
						echo "<p>", _('Question with last attempt is displayed for your review only'), "</p>";
						
						if (!$noraw && $showeachscore) {
							//$colors = scorestocolors($rawscores[$next], '', $qi[$questions[$next]]['answeights'], $noraw);
							if (strpos($rawscores[$next],'~')!==false) {
								$colors = explode('~',$rawscores[$next]);
							} else {
								$colors = array($rawscores[$next]);
							}
						} else {
							$colors = array();
						}
						$qshowans = ((($showansafterlast && $qi[$questions[$next]]['showans']=='0') || $qi[$questions[$next]]['showans']=='F' || $qi[$questions[$next]]['showans']=='J') || ($showansduring && $qi[$questions[$next]]['showans']=='0' && $attempts[$next]>=$testsettings['showans']));
						if ($qshowans) {
							displayq($next,$qi[$questions[$next]]['questionsetid'],$seeds[$next],2,false,$attempts[$next],false,false,false,$colors);
						} else {
							displayq($next,$qi[$questions[$next]]['questionsetid'],$seeds[$next],false,false,$attempts[$next],false,false,false,$colors);
						}
					}
					echo "</div>\n";
				}
			} 
			if (isset($_GET['done'])) { //are all done

				$shown = showscores($questions,$attempts,$testsettings);
				endtest($testsettings);
				if ($shown) {leavetestmsg();}
			}
		} else if ($_GET['action']=="seq") {
			if (isset($_GET['score'])) { //score a problem
				$qn = $_GET['score'];
				if ($_POST['verattempts']!=$attempts[$qn]) {
					echo "<p>", _('The last question has been submitted since you viewed it, and that score is shown below. Your answer just submitted was not scored or recorded.'), "</p>";
				} else {
					if (isset($_POST['disptime']) && !$isreview) {
						$used = $now - intval($_POST['disptime']);
						$timesontask[$qn] .= (($timesontask[$qn]=='')?'':'~').$used;
					}
					$GLOBALS['scoremessages'] = '';
					$rawscore = scorequestion($qn);
					//record score
					recordtestdata();
				}
				
				echo "<div class=review style=\"margin-top:5px;\">\n";
				if ($GLOBALS['scoremessages'] != '') {
					echo '<p>'.$GLOBALS['scoremessages'].'</p>';
				}
				$reattemptsremain = false;
				if ($showeachscore) {
					$possible = $qi[$questions[$qn]]['points'];
					if (getpts($rawscore)!=getpts($scores[$qn])) {
						echo "<p>", _('Score before penalty on last attempt: ');
						echo printscore($rawscore,$qn);
						echo "</p>";
					}
					//echo "<p>";
					//echo "Score on last attempt: ";
					echo "<p>", _('Score on last attempt: ');
					echo printscore($scores[$qn],$qn);
					echo "</p>\n";
					echo "<p>", _('Score in gradebook: ');
					echo printscore($bestscores[$qn],$qn);
					echo "</p>";
					 
					if (hasreattempts($qn)) {
						echo "<p><a href=\"showtest.php?action=seq&amp;to=$qn&amp;reattempt=$qn\">", _('Reattempt last question'), "</a></p>\n";
						$reattemptsremain = true; 
					}
				}
				if ($allowregen && $qi[$questions[$qn]]['allowregen']==1) {
					echo "<p><a href=\"showtest.php?action=seq&amp;to=$qn&amp;regen=$qn\">", _('Try another similar question'), "</a></p>\n";
				}
				unset($toshow);
				if (canimprove($qn) && $showeachscore) {
					$toshow = $qn;
				} else {
					for ($i=$qn+1;$i<count($questions);$i++) {
						if (unans($scores[$i]) || amreattempting($i)) {
							$toshow=$i;
							$done = false;
							break;
						}
					}
					if (!isset($toshow)) {
						for ($i=0;$i<$qn;$i++) {
							if (unans($scores[$i]) || amreattempting($i)) {
								$toshow=$i;
								$done = false;
								break;
							}
						}
					}
				}
				if (!isset($toshow)) { //no more to show
					$done = true;
				} 
				if (!$done) {
					echo "<p>", _('Question scored. <a href="#curq">Continue with assessment</a>, or when you are done click <a href="showtest.php?action=seq&amp;done=true">here</a> to see a summary of your score.'), "</p>\n";
					echo "</div>\n";
					echo "<hr/>";
				} else {
					echo "</div>\n";
					//echo "<a href=\"showtest.php?action=skip&done=true\">Click here to finalize and score test</a>\n";
				}
				
				
			}
			if (isset($_GET['to'])) { //jump to a problem
				$toshow = $_GET['to'];
			}
			if ($done || isset($_GET['done'])) { //are all done

				$shown = showscores($questions,$attempts,$testsettings);
				endtest($testsettings);
				if ($shown) {leavetestmsg();}
			} else { //show more test 
				echo filter("<div id=intro class=hidden>{$testsettings['intro']}</div>\n");
				
				echo "<form id=\"qform\" method=\"post\" enctype=\"multipart/form-data\" action=\"showtest.php?action=seq&amp;score=$toshow\" onsubmit=\"return doonsubmit(this,false,true)\">\n";
				echo "<input type=\"hidden\" name=\"asidverify\" value=\"$testid\" />";
				echo '<input type="hidden" name="disptime" value="'.time().'" />';
				echo "<input type=\"hidden\" name=\"isreview\" value=\"". ($isreview?1:0) ."\" />";
				echo "<input type=\"hidden\" name=\"verattempts\" value=\"{$attempts[$toshow]}\" />";
				
				for ($i = 0; $i < count($questions); $i++) {
					if (isset($intropieces)) {
						foreach ($introdividers as $k=>$v) {
							if ($v[1]==$i+1) {//right divider
								echo '<div class="intro" id="intropiece'.$k.'">'.filter($intropieces[$k]).'</div>';
								break;
							}
						}
					}
					$qavail = seqshowqinfobar($i,$toshow);
					
					if ($i==$toshow) {
						echo '<div class="curquestion">';
						basicshowq($i,false);
						echo '</div>';
					} else if ($qavail) {
						echo "<div class=todoquestion>";
						basicshowq($i,true);
						echo "</div>";
					} else {
						basicshowq($i,true);
					}
					
					if ($i==$toshow) {
						echo "<div><input type=\"submit\" class=\"btn\" value=\"", sprintf(_('Submit Question %d'), ($i+1)), "\" /></div><p></p>\n";
					}
					echo '<hr class="seq"/>';
				}
				
			}
		} else if ($_GET['action']=='embeddone') {
			$shown = showscores($questions,$attempts,$testsettings);
			endtest($testsettings);
			if ($shown) {leavetestmsg();}
		} else if ($_GET['action']=='scoreembed') {
			$qn = $_POST['toscore'];
			$colors = array();
			$page = $_GET['page'];
			$divopen = false;
			if ($_POST['verattempts']!=$attempts[$qn]) {
				echo '<div class="prequestion">';
				echo _('This question has been submittted since you viewed it, and that grade is shown below.  Your answer just submitted was not scored or recorded.');
				$divopen = true;
			} else {
				if (isset($_POST['disptime']) && !$isreview) {
					$used = $now - intval($_POST['disptime']);
					$timesontask[$qn] .= (($timesontask[$qn]=='')?'':'~').$used;
				}
				$GLOBALS['scoremessages'] = '';
				$GLOBALS['questionmanualgrade'] = false;
				$rawscore = scorequestion($qn);
				
				//record score
				recordtestdata();
				
				//is it video question?
				if ($testsettings['displaymethod'] == "VideoCue") {
				
					$viddata = unserialize($testsettings['viddata']);
					
					foreach ($viddata as $i=>$v) {
						if ($i>0 && isset($v[2]) && $v[2]==$qn) {
							echo '<div>';
							$hascontinue = true;
							if (isset($v[3]) && getpts($rawscore)>.99) {
								echo '<span class="inlinebtn" onclick="thumbSet.jumpToTime('.$v[1].',true);">';
								echo sprintf(_('Continue video to %s'), $v[5]), '</span> ';
								if (isset($viddata[$i+1])) {
									echo '<span class="inlinebtn" onclick="thumbSet.jumpToTime('.$v[3].',false);">';
									echo sprintf(_('Jump video to %s'), $viddata[$i+1][0]), '</span> ';
								} 	
							} else if (isset($v[3])) {
								echo '<span class="inlinebtn" onclick="thumbSet.jumpToTime('.$v[1].',true);">';
								echo sprintf(_('Continue video to %s'), $v[5]), '</span> ';
							} else if (isset($viddata[$i+1])) {
								echo '<span class="inlinebtn" onclick="thumbSet.jumpToTime('.$v[1].',true);">';
								echo sprintf(_('Continue video to %s'), $viddata[$i+1][0]), '</span> ';
							} else {
								$hascontinue = false;
							}
							if (hasreattempts($qn) && getpts($rawscore)<.99) {
								if ($hascontinue) {
									echo _('or try the problem again');
								} else {
									echo _('Try the problem again');
								}
							}
							
							echo '</div>';
							break;	
						}
					}
				}
				
				embedshowicon($qn);
				if (!$sessiondata['istutorial']) {
					echo '<div class="prequestion">';
					$divopen = true;
					if ($GLOBALS['scoremessages'] != '') {
						echo '<p>'.$GLOBALS['scoremessages'].'</p>';
					}
					$reattemptsremain = false;
					if ($showeachscore) {
						$possible = $qi[$questions[$qn]]['points'];
						if (getpts($rawscore)!=getpts($scores[$qn])) {
							echo "<p>", _('Score before penalty on last attempt: ');
							echo printscore($rawscore,$qn);
							echo "</p>";
						}
						echo "<p>";
						echo _('Score on last attempt: ');
						echo printscore($scores[$qn],$qn);
						echo "<br/>\n";
						echo _('Score in gradebook: ');
						echo printscore($bestscores[$qn],$qn);
						if ($GLOBALS['questionmanualgrade'] == true) {
							echo '<br/><strong>', _('Note:'), '</strong> ', _('This question contains parts that can not be auto-graded.  Those parts will count as a score of 0 until they are graded by your instructor');
						} 
						echo "</p>";
						
						
					} else {
						echo '<p>', _('Question scored.'), '</p>';
					}
				}
				if ($showeachscore && $GLOBALS['questionmanualgrade'] != true) {
					if (!$noraw) {
						if (strpos($rawscores[$qn],'~')!==false) {
							$colors = explode('~',$rawscores[$qn]);
						} else {
							$colors = array($rawscores[$qn]);
						}
					} else {
						$colors = scorestocolors($noraw?$scores[$qn]:$rawscores[$qn],$qi[$questions[$qn]]['points'],$qi[$questions[$qn]]['answeights'],$noraw);
					}
				}
				
				
			}
			if ($allowregen && $qi[$questions[$qn]]['allowregen']==1) {
				echo "<p><a href=\"showtest.php?regen=$qn&page=$page\">", _('Try another similar question'), "</a></p>\n";
			}
			if (hasreattempts($qn)) {
				if ($divopen) { echo '</div>';}
					
				ob_start();
				basicshowq($qn,false,$colors);
				$quesout = ob_get_clean();
				$quesout = substr($quesout,0,-7).'<br/><input type="button" class="btn" value="' . _('Submit') . '" onclick="assessbackgsubmit('.$qn.',\'submitnotice'.$qn.'\')" /><span id="submitnotice'.$qn.'"></span></div>';
				echo $quesout;
				
			} else {
				if (!$sessiondata['istutorial']) {
					echo "<p>", _('No attempts remain on this problem.'), "</p>";
					if ($showeachscore) {
						//TODO i18n
						$msg =  "<p>This question, with your last answer";
						if (($showansafterlast && $qi[$questions[$qn]]['showans']=='0') || $qi[$questions[$qn]]['showans']=='F' || $qi[$questions[$qn]]['showans']=='J') {
							$msg .= " and correct answer";
							$showcorrectnow = true;
						} else if ($showansduring && $qi[$questions[$qn]]['showans']=='0' && $qi[$questions[$qn]]['showans']=='0' && $testsettings['showans']==$attempts[$qn]) {
							$msg .= " and correct answer";
							$showcorrectnow = true;
						} else {
							$showcorrectnow = false;
						}
						if ($showcorrectnow) {
							echo $msg . ', is displayed below</p>';
							echo '</div>';
							displayq($qn,$qi[$questions[$qn]]['questionsetid'],$seeds[$qn],2,false,$attempts[$qn],false,false,true,$colors);
						} else {
							echo $msg . ', is displayed below</p>';
							echo '</div>';
							displayq($qn,$qi[$questions[$qn]]['questionsetid'],$seeds[$qn],0,false,$attempts[$qn],false,false,true,$colors);
						}
						
					} else {
						echo '</div>';
						if ($testsettings['showans']!='N') {
							displayq($qn,$qi[$questions[$qn]]['questionsetid'],$seeds[$qn],0,false,$attempts[$qn],false,false,true,$colors);
						}
					}
				} else {
					if ($divopen) { echo '</div>';}
				}
					
			}
			
			showqinfobar($qn,true,false,true);
			
			echo '<script type="text/javascript">document.getElementById("disptime").value = '.time().';';
			if (strpos($testsettings['intro'],'[PAGE')!==false || $sessiondata['intreereader']) {
				echo 'embedattemptedtrack["q'.$qn.'"][1]=0;';
				if (false && $showeachscore) {
					echo 'embedattemptedtrack["q'.$qn.'"][2]='. (canimprove($qn)?"1":"0") . ';';
				}
				if ($showeachscore) {
					$pts = getpts($bestscores[$qn]);
					echo 'embedattemptedtrack["q'.$qn.'"][3]='. (($pts>0)?$pts:0) . ';';
				}
				echo 'updateembednav();';
			}
			echo '</script>';
			exit;
			
		}
	} else { //starting test display  
		$canimprove = false;
		$hasreattempts = false;
		$ptsearned = 0;
		$perfectscore = false;
		
		for ($j=0; $j<count($questions);$j++) {
			$canimproveq[$j] = canimprove($j);
			$hasreattemptsq[$j] = hasreattempts($j);
			if ($canimproveq[$j]) {
				$canimprove = true;
			}
			if ($hasreattemptsq[$j]) {
				$hasreattempts = true;
			}
			$ptsearned += getpts($scores[$j]);
		}
		if ($testsettings['timelimit']>0 && !$isreview && !$superdone && $remaining < 0) {
			echo '<script type="text/javascript">';
			echo 'initstack.push(function() {';
			if ($timelimitkickout) {
				echo 'alert("', _('Your time limit has expired.  If you try to submit any questions, your submissions will be rejected.'), '");';
			} else {
				echo 'alert("', _('Your time limit has expired.  If you submit any questions, your assessment will be marked overtime, and will have to be reviewed by your instructor.'), '");';
			}
			echo '});</script>';
		}
		if ($testsettings['displaymethod'] != "Embed") {
			$testsettings['intro'] .= "<p>" . _('Total Points Possible: ') . totalpointspossible($qi) . "</p>";
		}
		if ($testsettings['isgroup']>0) {
			$testsettings['intro'] .= "<p><span style=\"color:red;\">" . _('This is a group assessment.  Any changes effect all group members.') . "</span><br/>";
			if (!$isteacher || isset($sessiondata['actas'])) {
				$testsettings['intro'] .= _('Group Members:') . " <ul>";

				// SELECT#24
				$query = "SELECT imas_users.id,imas_users.FirstName,imas_users.LastName FROM imas_users,imas_assessment_sessions WHERE ";
				$query .= "imas_users.id=imas_assessment_sessions.userid AND imas_assessment_sessions.agroupid='{$sessiondata['groupid']}' ORDER BY imas_users.LastName,imas_users.FirstName";
				$result = mysql_query($query) or die("Query failed : $query;  " . mysql_error());
				while ($row = mysql_fetch_row($result)) {
					$curgrp[] = $row[0];
					$testsettings['intro'] .= "<li>{$row[2]}, {$row[1]}</li>";
				}
				$testsettings['intro'] .= "</ul>";
			
				if ($testsettings['isgroup']==1 || $testsettings['isgroup']==2) {
					if (count($curgrp)<$testsettings['groupmax']) {
						$testsettings['intro'] .= "<a href=\"showtest.php?addgrpmem=true\">" . _('Add Group Members') . "</a></p>";
					} else {
						$testsettings['intro'] .= '</p>';
					}
				} else {
					$testsettings['intro'] .= '</p>';
				}
			}
		}
		if ($ptsearned==totalpointspossible($qi)) {
			$perfectscore = true; 
		} 
		if ($testsettings['displaymethod'] == "AllAtOnce") {
			echo filter("<div class=intro>{$testsettings['intro']}</div>\n");
			echo "<form id=\"qform\" method=\"post\" enctype=\"multipart/form-data\" action=\"showtest.php?action=scoreall\" onsubmit=\"return doonsubmit(this,true)\">\n";
			echo "<input type=\"hidden\" name=\"asidverify\" value=\"$testid\" />";
			echo '<input type="hidden" name="disptime" value="'.time().'" />';
			echo "<input type=\"hidden\" name=\"isreview\" value=\"". ($isreview?1:0) ."\" />";
			$numdisplayed = 0;
			for ($i = 0; $i < count($questions); $i++) {
				if (unans($scores[$i]) || amreattempting($i)) {
					basicshowq($i);
					showqinfobar($i,true,false);
					$numdisplayed++;
				}
			}
			if ($numdisplayed > 0) {
				echo '<br/><input type="submit" class="btn" value="', _('Submit'), '" />';
				echo '<input type="submit" class="btn" name="saveforlater" value="', _('Save answers'), '" onclick="return confirm(\'', _('This will save your answers so you can come back later and finish, but not submit them for grading. Be sure to come back and submit your answers before the due date.'), '\');" />';
				echo "</form>\n";
			} else {
				startoftestmessage($perfectscore,$hasreattempts,$allowregen,$noindivscores,$testsettings['testtype']=="NoScores");
				echo "</form>\n";
				leavetestmsg();
				
			}
		} else if ($testsettings['displaymethod'] == "OneByOne") {
			for ($i = 0; $i<count($questions);$i++) {
				if (unans($scores[$i]) || amreattempting($i)) {
					break;
				}
			}
			if ($i == count($questions)) {
				startoftestmessage($perfectscore,$hasreattempts,$allowregen,$noindivscores,$testsettings['testtype']=="NoScores");
			
				leavetestmsg();
				
			} else {
				echo filter("<div class=intro>{$testsettings['intro']}</div>\n");
				echo "<form id=\"qform\" method=\"post\" enctype=\"multipart/form-data\" action=\"showtest.php?action=shownext&amp;score=$i\" onsubmit=\"return doonsubmit(this)\">\n";
				echo "<input type=\"hidden\" name=\"asidverify\" value=\"$testid\" />";
				echo '<input type="hidden" name="disptime" value="'.time().'" />';
				echo "<input type=\"hidden\" name=\"isreview\" value=\"". ($isreview?1:0) ."\" />";
				basicshowq($i);
				showqinfobar($i,true,true);
				echo '<input type="submit" class="btn" value="', _('Next'), '" />';
				echo "</form>\n";
			}
		} else if ($testsettings['displaymethod'] == "SkipAround") {
			echo filter("<div class=intro>{$testsettings['intro']}</div>\n");
			
			for ($i = 0; $i<count($questions);$i++) {
				if (unans($scores[$i]) || amreattempting($i)) {
					break;
				}
			}
			shownavbar($questions,$scores,$i,$testsettings['showcat']);
			if ($i == count($questions)) {
				echo "<div class=inset><br/>\n";
				echo "<a name=\"beginquestions\"></a>\n";
				
				startoftestmessage($perfectscore,$hasreattempts,$allowregen,$noindivscores,$testsettings['testtype']=="NoScores");
				
				leavetestmsg();
				
			} else {
				echo "<div class=inset>\n";
				if (isset($intropieces)) {
					foreach ($introdividers as $k=>$v) {
						if ($v[1]<=$i+1 && $i+1<=$v[2]) {//right divider
							echo '<div><a href="#" id="introtoggle'.$k.'" onclick="toggleintroshow('.$k.'); return false;">', _('Hide Question Information'), '</a></div>';
							echo '<div class="intro" id="intropiece'.$k.'">'.filter($intropieces[$k]).'</div>';
							break;
						}
					}
				}	
				echo "<form id=\"qform\" method=\"post\" enctype=\"multipart/form-data\" action=\"showtest.php?action=skip&amp;score=$i\" onsubmit=\"return doonsubmit(this)\">\n";
				echo "<input type=\"hidden\" name=\"asidverify\" value=\"$testid\" />";
				echo '<input type="hidden" name="disptime" value="'.time().'" />';
				echo "<input type=\"hidden\" name=\"isreview\" value=\"". ($isreview?1:0) ."\" />";
				echo "<a name=\"beginquestions\"></a>\n";
				basicshowq($i);
				showqinfobar($i,true,true);
				echo '<input type="submit" class="btn" value="', _('Submit'), '" />';
				if (($testsettings['showans']=='J' && $qi[$questions[$i]]['showans']=='0') || $qi[$questions[$i]]['showans']=='J') {
						echo ' <input type="button" class="btn" value="', _('Jump to Answer'), '" onclick="if (confirm(\'', _('If you jump to the answer, you must generate a new version to earn credit'), '\')) {window.location = \'showtest.php?action=skip&amp;jumptoans='.$i.'&amp;to='.$i.'\'}"/>';
					}
				echo "</form>\n";
				echo "</div>\n";
				
			}
		} else if ($testsettings['displaymethod'] == "Seq") {
			for ($i = 0; $i<count($questions);$i++) {
				if ($canimproveq[$i]) {
					break;
				}
			}
			if ($i == count($questions)) {
				startoftestmessage($perfectscore,$hasreattempts,$allowregen,$noindivscores,$testsettings['testtype']=="NoScores");
				
				leavetestmsg();
				
			} else {
				$curq = $i;
				echo filter("<div class=intro>{$testsettings['intro']}</div>\n");
				echo "<form id=\"qform\" method=\"post\" enctype=\"multipart/form-data\" action=\"showtest.php?action=seq&amp;score=$i\" onsubmit=\"return doonsubmit(this,false,true)\">\n";
				echo "<input type=\"hidden\" name=\"asidverify\" value=\"$testid\" />";
				echo '<input type="hidden" name="disptime" value="'.time().'" />';
				echo "<input type=\"hidden\" name=\"isreview\" value=\"". ($isreview?1:0) ."\" />";
				echo "<input type=\"hidden\" name=\"verattempts\" value=\"{$attempts[$i]}\" />";
				for ($i = 0; $i < count($questions); $i++) {
					if (isset($intropieces)) {
						foreach ($introdividers as $k=>$v) {
							if ($v[1]==$i+1) {//right divider
								echo '<div class="intro" id="intropiece'.$k.'">'.filter($intropieces[$k]).'</div>';
								break;
							}
						}
					}
					$qavail = seqshowqinfobar($i,$curq);
					
					if ($i==$curq) {
						echo '<div class="curquestion">';
						basicshowq($i,false);
						echo '</div>';
					} else if ($qavail) {
						echo "<div class=todoquestion>";
						basicshowq($i,true);
						echo "</div>";
					} else {
						basicshowq($i,true);
					}
					if ($i==$curq) {
						echo "<div><input type=\"submit\" class=\"btn\" value=\"", sprintf(_('Submit Question %d'), ($i+1)), "\" /></div><p></p>\n";
					}
					
					echo '<hr class="seq"/>';
				}
				echo '</form>';
			}
		} else if ($testsettings['displaymethod'] == "Embed" || $testsettings['displaymethod'] == "VideoCue") {
			if (!isset($_GET['page'])) { $_GET['page'] = 0;}
			$intro = filter("<div class=\"intro\">{$testsettings['intro']}</div>\n");
			if ($testsettings['displaymethod'] == "VideoCue") {
				echo $intro;
				$intro = '';
			}
			echo '<script type="text/javascript">var assesspostbackurl="' .$urlmode. $_SERVER['HTTP_HOST'] . $imasroot . '/assessment/showtest.php?embedpostback=true&action=scoreembed&page='.$_GET['page'].'";</script>';
			//using the full test scoreall action for timelimit auto-submits
			echo "<form id=\"qform\" method=\"post\" enctype=\"multipart/form-data\" action=\"showtest.php?action=scoreall\" onsubmit=\"return doonsubmit(this,false,true)\">\n";
			if (strpos($intro,'[PAGE')===false && $testsettings['displaymethod'] != "VideoCue") {
				echo '<div class="formcontents" style="margin-left:20px;">';
			}
			echo "<input type=\"hidden\" id=\"asidverify\" name=\"asidverify\" value=\"$testid\" />";
			echo '<input type="hidden" id="disptime" name="disptime" value="'.time().'" />';
			echo "<input type=\"hidden\" id=\"isreview\" name=\"isreview\" value=\"". ($isreview?1:0) ."\" />";
			
			//TODO i18n
			if (strpos($intro,'[QUESTION')===false) {
				if (isset($intropieces)) {
					$last = 1;
					foreach ($introdividers as $k=>$v) {
						if ($last<$v[1]-1) {
							for ($j=$last;$j<$v[1];$j++) {
								$intro .= '[QUESTION '.$j.']';
							}
						}
						$intro .= '<div class="intro" id="intropiece'.$k.'">'.$intropieces[$k].'</div>';
						for ($j=$v[1];$j<=$v[2];$j++) {
							$intro .= '[QUESTION '.$j.']';
							$last = $j;
						}
					}
					if ($last < count($questions)) {
						for ($j=$last+1;$j<=count($questions);$j++) {
							$intro .= '[QUESTION '.$j.']';
						}
					}
				} else {
					for ($j=1;$j<=count($questions);$j++) {
						$intro .= '[QUESTION '.$j.']';
					}
				}
			} else {
				$intro = preg_replace('/<p>((<span|<strong|<em)[^>]*>)?\[QUESTION\s+(\d+)\s*\]((<\/span|<\/strong|<\/em)[^>]*>)?<\/p>/','[QUESTION $3]',$intro);
				$intro = preg_replace('/\[QUESTION\s+(\d+)\s*\]/','</div>[QUESTION $1]<div class="intro">',$intro);
			}
			if (strpos($intro,'[PAGE')!==false) {
				$intro = preg_replace('/<p>((<span|<strong|<em)[^>]*>)?\[PAGE\s*([^\]]*)\]((<\/span|<\/strong|<\/em)[^>]*>)?<\/p>/','[PAGE $3]',$intro);
				$intro = preg_replace('/\[PAGE\s*([^\]]*)\]/','</div>[PAGE $1]<div class="intro">',$intro);
				$intropages = preg_split('/\[PAGE\s*([^\]]*)\]/',$intro,-1,PREG_SPLIT_DELIM_CAPTURE); //main pagetitle cont 1 pagetitle
				if (!isset($_GET['page'])) { $_GET['page'] = 0;}
				if ($_GET['page']==0) {
					echo $intropages[0];	
				} 
				$intro =  $intropages[2*$_GET['page']+2];
				preg_match_all('/\[QUESTION\s+(\d+)\s*\]/',$intro,$matches,PREG_PATTERN_ORDER);
				if (isset($matches[1]) && count($matches[1])>0) {
					$qmin = min($matches[1])-1;
					$qmax = max($matches[1]);
				} else {
					$qmin =0; $qmax = 0;
				}
				$dopage = true;
				$dovidcontrol = false;
				showembednavbar($intropages,$_GET['page']);
				echo "<div class=inset>\n";
				echo "<a name=\"beginquestions\"></a>\n";
			} else if ($testsettings['displaymethod'] == "VideoCue") {
				$viddata = unserialize($testsettings['viddata']);
			
				//asychronously load YouTube API
				//echo '<script type="text/javascript">var tag = document.createElement(\'script\');tag.src = "//www.youtube.com/player_api";var firstScriptTag = document.getElementsByTagName(\'script\')[0];firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);</script>';
				echo '<script type="text/javascript">var tag = document.createElement(\'script\');tag.src = "//www.youtube.com/player_api";var firstScriptTag = document.getElementsByTagName(\'script\')[0];firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);</script>';

				
				  //tag.src = "//www.youtube.com/iframe_api";
				showvideoembednavbar($viddata);
				$dovidcontrol = true;
				echo '<div class="inset" style="position: relative; margin-left: 225px;">';
				echo "<a name=\"beginquestions\"></a>\n";
				echo '<div id="playerwrapper"><div id="player"></div></div>';
				$outarr = array();
				for ($i=1;$i<count($viddata);$i++) {
					if (isset($viddata[$i][2])) {
						$outarr[] = $viddata[$i][1].':{qn:'.$viddata[$i][2].'}';
					}
				}
				echo '<script type="text/javascript">var thumbSet = initVideoObject("'.$viddata[0].'",{'.implode(',',$outarr).'}); </script>';
				
				$qmin = 0;
				$qmax = count($questions);
				$dopage = false;
			} else {
				$qmin = 0;
				$qmax = count($questions);
				$dopage = false;
				$dovidcontrol = false;
				showembedupdatescript();
			}
						
			for ($i = $qmin; $i < $qmax; $i++) {
				if ($qi[$questions[$i]]['points']==0 || $qi[$questions[$i]]['withdrawn']==1) {
					$intro = str_replace('[QUESTION '.($i+1).']','',$intro);
					continue;
				}
				$quesout = '<div id="embedqwrapper'.$i.'" class="embedqwrapper"';
				if ($dovidcontrol) { $quesout .= ' style="position: absolute; width:100%; visibility:hidden; top:0px;left:-1000px;" ';}
				$quesout .= '>';
				ob_start();
				embedshowicon($i);
				if (hasreattempts($i)) {
					
					basicshowq($i,false);
					$quesout .= ob_get_clean();
					$quesout = substr($quesout,0,-7).'<br/><input type="button" class="btn" value="Submit" onclick="assessbackgsubmit('.$i.',\'submitnotice'.$i.'\')" /><span id="submitnotice'.$i.'"></span></div>';
					
				} else {
					if (!$sessiondata['istutorial']) {
						echo '<div class="prequestion">';
						echo "<p>", _('No attempts remain on this problem.'), "</p>";
						if ($allowregen && $qi[$questions[$i]]['allowregen']==1) {
							echo "<p><a href=\"showtest.php?regen=$i\">", _('Try another similar question'), "</a></p>\n";
						}
						if ($showeachscore) {
							//TODO i18n
							$msg =  "<p>This question, with your last answer";
							if (($showansafterlast && $qi[$questions[$i]]['showans']=='0') || $qi[$questions[$i]]['showans']=='F' || $qi[$questions[$i]]['showans']=='J') {
								$msg .= " and correct answer";
								$showcorrectnow = true;
							} else if ($showansduring && $qi[$questions[$i]]['showans']=='0' && $qi[$questions[$i]]['showans']=='0' && $testsettings['showans']==$attempts[$i]) {
								$msg .= " and correct answer";
								$showcorrectnow = true;
							} else {
								$showcorrectnow = false;
							}
							if ($showcorrectnow) {
								echo $msg . ', is displayed below</p>';
								echo '</div>';
								displayq($i,$qi[$questions[$i]]['questionsetid'],$seeds[$i],2,false,$attempts[$i],false,false,true);
							} else {
								echo $msg . ', is displayed below</p>';
								echo '</div>';
								displayq($i,$qi[$questions[$i]]['questionsetid'],$seeds[$i],0,false,$attempts[$i],false,false,true);
							}
							
						} else {
							echo '</div>';
							if ($testsettings['showans']!='N') {
								displayq($i,$qi[$questions[$i]]['questionsetid'],$seeds[$i],0,false,$attempts[$i],false,false,true);
							}
						}
					}
					
					$quesout .= ob_get_clean();
				}
				ob_start();
				showqinfobar($i,true,false);
				$reviewbar = ob_get_clean();
				if (!$sessiondata['istutorial']) {
					$reviewbar = str_replace('<div class="review">','<div class="review">'._('Question').' '.($i+1).'. ', $reviewbar);
				}
				$quesout .= $reviewbar;
				$quesout .= '</div>';
				$intro = str_replace('[QUESTION '.($i+1).']',$quesout,$intro);
			}
			$intro = preg_replace('/<div class="intro">\s*(&nbsp;|<p>\s*<\/p>|<\/p>|\s*)\s*<\/div>/','',$intro);
			echo $intro;
			
			if ($dopage==true) {
				echo '<p>';
				if ($_GET['page']>0) {
					echo '<a href="showtest.php?page='.($_GET['page']-1).'">Previous Page</a> ';
				}
				if ($_GET['page']<(count($intropages)-1)/2-1) {
					if ($_GET['page']>0) { echo '| ';}
					echo '<a href="showtest.php?page='.($_GET['page']+1).'">Next Page</a>';
				}
				echo '</p>';
			}
			if (!$sessiondata['istutorial'] && $testsettings['displaymethod'] != "VideoCue") {
				echo "<p>" . _('Total Points Possible: ') . totalpointspossible($qi) . "</p>";
			}
			if (!$sessiondata['istutorial']) {
				echo "<p><a href=\"showtest.php?action=embeddone\">", _('When you are done, click here to see a summary of your score'), "</a></p>\n";
			}
			
			echo '</div>'; //ends either inset or formcontents div
			echo '</form>';
			
					
			
		}
	}
	//IP:  eqntips
	
	require("../footer.php");
	
	function showembedupdatescript() {
		global $imasroot,$scores,$bestscores,$showeachscore,$qi,$questions,$testsettings;
		
		$jsonbits = array();
		$pgposs = 0;
		for($j=0;$j<count($scores);$j++) {
			$bit = "\"q$j\":[0,";
			if (unans($scores[$j])) {
				$cntunans++;
				$bit .= "1,";
			} else {
				$bit .= "0,";
			}
			if (canimprove($j)) {
				$cntcanimp++;
				$bit .= "1,";
			} else {
				$bit .= "0,";
			}
			$curpts = getpts($bestscores[$j]);
			if ($curpts<0) { $curpts = 0;}
			$bit .= $curpts.']';
			$pgposs += $qi[$questions[$j]]['points'];
			$pgpts += $curpts;
			$jsonbits[] = $bit;
		}
		echo '<script type="text/javascript">var embedattemptedtrack = {'.implode(',',$jsonbits).'}; </script>';
		echo '<script type="text/javascript">function updateembednav() {
			var unanscnt = 0;
			var canimpcnt = 0;
			var pts = 0;
			var qcnt = 0;
			for (var i in embedattemptedtrack) {
				if (embedattemptedtrack[i][1]==1) {
					unanscnt++;
				}
				if (embedattemptedtrack[i][2]==1) {
					canimpcnt++;
				}
				pts += embedattemptedtrack[i][3];
				qcnt++;
			}
			var status = 0;';
			if ($showeachscore) {
				echo 'if (pts == '.$pgposs.') {status=2;} else if (unanscnt<qcnt) {status=1;}';	
			} else {
				echo 'if (unanscnt == 0) { status = 2;} else if (unanscnt<qcnt) {status=1;}';
			}
			echo 'if (top !== self) {
				try {
					top.updateTRunans("'.$testsettings['id'].'", status);
				} catch (e) {}
			}
		      }</script>';
	}
	
	function showvideoembednavbar($viddata) {
		global $imasroot,$scores,$bestscores,$showeachscore,$qi,$questions,$testsettings;
		/*viddata[0] should be video id.  After that, should be [
		0: title for previous video segment, 
		1: time to showQ / end of video segment, (in seconds)
		2: qn, 
		3: time to jump to if right (and time for next link to start at) (in seconds)
		4: provide a link to watch directly after Q (T/F), 
		5: title for the part immediately following the Q]
		*/
		echo "<a href=\"#beginquestions\"><img class=skipnav src=\"$imasroot/img/blank.gif\" alt=\"", _('Skip Navigation'), "\" /></a>\n";
		echo '<div class="navbar" style="width:175px">';
		echo '<ul class="qlist" style="margin-left:-10px">';
		$timetoshow = 0;
		for ($i=1; $i<count($viddata); $i++) {
			echo '<li style="margin-bottom:7px;">';
			echo '<a href="#" onclick="thumbSet.jumpToTime('.$timetoshow.',true);return false;">'.$viddata[$i][0].'</a>';
			if (isset($viddata[$i][2])) {
				echo '<br/>&nbsp;&nbsp;<a style="font-size:75%;" href="#" onclick="thumbSet.jumpToQ('.$viddata[$i][1].',false);return false;">', _('Jump to Question'), '</a>';
				if (isset($viddata[$i][4]) && $viddata[$i][4]==true) {
					echo '<br/>&nbsp;&nbsp;<a style="font-size:75%;" href="#" onclick="thumbSet.jumpToTime('.$viddata[$i][1].',true);return false;">'.$viddata[$i][5].'</a>';
				}
			}
			if (isset($viddata[$i][3])) {
				$timetoshow = $viddata[$i][3];
			} else if (isset($viddata[$i][1])) {
				$timetoshow = $viddata[$i][1];
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';
		showembedupdatescript();
	}
	
	function showembednavbar($pginfo,$curpg) {
		global $imasroot,$scores,$bestscores,$showeachscore,$qi,$questions,$testsettings;
		echo "<a href=\"#beginquestions\"><img class=skipnav src=\"$imasroot/img/blank.gif\" alt=\"", _('Skip Navigation'), "\" /></a>\n";
		
		echo '<div class="navbar fixedonscroll" style="width:125px">';
		echo "<h4>", _('Pages'), "</h4>\n";
		echo '<ul class="qlist" style="margin-left:-10px">';
		$jsonbits = array();
		$max = (count($pginfo)-1)/2;
		$totposs = 0;
		for ($i = 0; $i < $max; $i++) {
			echo '<li style="margin-bottom:7px;">';
			if ($curpg == $i) { echo "<span class=current>";}
			if (trim($pginfo[2*$i+1])=='') {
				$pginfo[2*$i+1] =  $i+1;
			}
			echo '<a href="showtest.php?page='.$i.'">'.$pginfo[2*$i+1].'</a>';
			if ($curpg == $i) { echo "</span>";}
			
			preg_match_all('/\[QUESTION\s+(\d+)\s*\]/',$pginfo[2*$i+2],$matches,PREG_PATTERN_ORDER);
			if (isset($matches[1]) && count($matches[1])>0) {
				$qmin = min($matches[1])-1;
				$qmax = max($matches[1]);
			
				$cntunans = 0;
				$cntcanimp = 0;
				$pgposs = 0;
				$pgpts = 0;
				for($j=$qmin;$j<$qmax;$j++) {
					$bit = "\"q$j\":[$i,";
					if (unans($scores[$j])) {
						$cntunans++;
						$bit .= "1,";
					} else {
						$bit .= "0,";
					}
					if (canimprove($j)) {
						$cntcanimp++;
						$bit .= "1,";
					} else {
						$bit .= "0,";
					}
					$curpts = getpts($bestscores[$j]);
					if ($curpts<0) { $curpts = 0;}
					$bit .= $curpts.']';
					$pgposs += $qi[$questions[$j]]['points'];
					$pgpts += $curpts;
					$jsonbits[] = $bit;
				}
				echo '<br/>';
				
				//if (false && $showeachscore) {
				///	echo "<br/><span id=\"embednavcanimp$i\" style=\"margin-left:8px\">$cntcanimp</span> can be improved";
				//}
				if ($showeachscore) {
					echo " <span id=\"embednavscore$i\" style=\"margin-left:8px\">".round($pgpts,1)." point".(($pgpts==1)?"":"s")."</span> out of $pgposs";
				} else {
					echo " <span id=\"embednavunans$i\" style=\"margin-left:8px\">$cntunans</span> unattempted";
				}
				$totposs += $pgposs;
			}
			echo "</li>\n";
		}
		echo '</ul>';
		echo '<script type="text/javascript">var embedattemptedtrack = {'.implode(',',$jsonbits).'}; </script>';
		echo '<script type="text/javascript">function updateembednav() {
			var unanscnt = [];
			var unanstot = 0; var ptstot = 0;
			var canimpcnt = [];
			var pgpts = [];
			var pgmax = -1;
			var qcnt = 0;
			for (var i in embedattemptedtrack) {
				if (embedattemptedtrack[i][0] > pgmax) {
					pgmax = embedattemptedtrack[i][0];
				}
				qcnt++;
			}
			for (var i=0; i<=pgmax; i++) {
				unanscnt[i] = 0;
				canimpcnt[i] = 0;
				pgpts[i] = 0;
				
			}
			for (var i in embedattemptedtrack) {
				if (embedattemptedtrack[i][1]==1) {
					unanscnt[embedattemptedtrack[i][0]]++;
					unanstot++;
				}
				if (embedattemptedtrack[i][2]==1) {
					canimpcnt[embedattemptedtrack[i][0]]++;
				}
				pgpts[embedattemptedtrack[i][0]] += embedattemptedtrack[i][3];
				ptstot += embedattemptedtrack[i][3];
			}
			for (var i=0; i<=pgmax; i++) {
				';
		//if (false && $showeachscore) {
		//		echo 'document.getElementById("embednavcanimp"+i).innerHTML = canimpcnt[i];';
		//}
		if ($showeachscore) {
				echo 'var el = document.getElementById("embednavscore"+i);';
				echo 'if (el != null) {';
				echo '	el.innerHTML = pgpts[i] + ((pgpts[i]==1) ? " point" : " points");';
		} else {
				echo 'var el = document.getElementById("embednavunans"+i);';
				echo 'if (el != null) {';
				echo '	el.innerHTML = unanscnt[i];';
		}
				
		echo '}}
			var status = 0;';
			if ($showeachscore) {
				echo 'if (ptstot == '.$totposs.') {status=2} else if (unanstot<qcnt) {status=1;}';	
			} else {
				echo 'if (unanstot == 0) { status = 2;} else if (unanstot<qcnt) {status=1;}';
			}
			echo 'if (top !== self) {
				try {
					top.updateTRunans("'.$testsettings['id'].'", status);
				} catch (e) {}
			}
		}</script>';
		echo '</div>';	
	}
	
	function shownavbar($questions,$scores,$current,$showcat) {
		global $imasroot,$isdiag,$testsettings,$attempts,$qi,$allowregen,$bestscores,$isreview,$showeachscore,$noindivscores,$CFG;
		$todo = 0;
		$earned = 0;
		$poss = 0;
		echo "<a href=\"#beginquestions\"><img class=skipnav src=\"$imasroot/img/blank.gif\" alt=\"", _('Skip Navigation'), "\" /></a>\n";
		echo "<div class=navbar>";
		echo "<h4>", _('Questions'), "</h4>\n";
		echo "<ul class=qlist>\n";
		for ($i = 0; $i < count($questions); $i++) {
			echo "<li>";
			if ($current == $i) { echo "<span class=current>";}
			if (unans($scores[$i]) || amreattempting($i)) {
				$todo++;
			}
			/*
			$icon = '';
			if ($attempts[$i]==0) {
				$icon = "full";
			} else if (hasreattempts($i)) {
				$icon = "half";
			} else {
				$icon = "empty";
			}
			echo "<img src=\"$imasroot/img/aicon/left$icon.gif\"/>";
			$icon = '';
			if (unans($bestscores[$i]) || getpts($bestscores[$i])==0) {
				$icon .= "empty";
			} else if (getpts($bestscores[$i]) == $qi[$questions[$i]]['points']) {
				$icon .= "full";
			} else {
				$icon .= "half";
			}
			if (!canimprovebest($i) && !$allowregen && $icon!='full') {
				$icon .= "ci";
			}
			echo "<img src=\"$imasroot/img/aicon/right$icon.gif\"/>";
			*/	
			if ($isreview) {
				$thisscore = getpts($scores[$i]);
			} else {
				$thisscore = getpts($bestscores[$i]);
			}
			if ((unans($scores[$i]) && $attempts[$i]==0) || ($noindivscores && amreattempting($i))) {
				if (isset($CFG['TE']['navicons'])) {
					echo "<img src=\"$imasroot/img/{$CFG['TE']['navicons']['untried']}\"/> ";
				} else {
				echo "<img src=\"$imasroot/img/q_fullbox.gif\"/> ";
				}
			} else if (canimprove($i) && !$noindivscores) {
				if (isset($CFG['TE']['navicons'])) {
					if ($thisscore==0 || $noindivscores) {
						echo "<img src=\"$imasroot/img/{$CFG['TE']['navicons']['canretrywrong']}\"/> ";
					} else {
						echo "<img src=\"$imasroot/img/{$CFG['TE']['navicons']['canretrypartial']}\"/> ";
					}
				} else {
				echo "<img src=\"$imasroot/img/q_halfbox.gif\"/> ";
				}
			} else {
				if (isset($CFG['TE']['navicons'])) {
					if (!$showeachscore) {
						echo "<img src=\"$imasroot/img/{$CFG['TE']['navicons']['noretry']}\"/> ";
					} else {
						if ($thisscore == $qi[$questions[$i]]['points']) {
							echo "<img src=\"$imasroot/img/{$CFG['TE']['navicons']['correct']}\"/> ";
						} else if ($thisscore==0) { 
							echo "<img src=\"$imasroot/img/{$CFG['TE']['navicons']['wrong']}\"/> ";
						} else {
							echo "<img src=\"$imasroot/img/{$CFG['TE']['navicons']['partial']}\"/> ";
						}
					}
				} else {
					echo "<img src=\"$imasroot/img/q_emptybox.gif\"/> ";
				}
			}
			
				
			if ($showcat>1 && $qi[$questions[$i]]['category']!='0') {
				if ($qi[$questions[$i]]['withdrawn']==1) {
					echo "<a href=\"showtest.php?action=skip&amp;to=$i\"><span class=\"withdrawn\">". ($i+1) . ") {$qi[$questions[$i]]['category']}</span></a>";
				} else {
					echo "<a href=\"showtest.php?action=skip&amp;to=$i\">". ($i+1) . ") {$qi[$questions[$i]]['category']}</a>";
				}
			} else {
				if ($qi[$questions[$i]]['withdrawn']==1) {
					echo "<a href=\"showtest.php?action=skip&amp;to=$i\"><span class=\"withdrawn\">Q ". ($i+1) . "</span></a>";
				} else {
					echo "<a href=\"showtest.php?action=skip&amp;to=$i\">Q ". ($i+1) . "</a>";
				}
			}
			if ($showeachscore) {
				if (($isreview && canimprove($i)) || (!$isreview && canimprovebest($i))) {
					echo ' (';
				} else {
					echo ' [';
				}
				if ($isreview) {
					$thisscore = getpts($scores[$i]);
				} else {
					$thisscore = getpts($bestscores[$i]);
				}
				if ($thisscore<0) {
					echo '0';
				} else {
					echo $thisscore;
					$earned += $thisscore;
				}
				echo '/'.$qi[$questions[$i]]['points'];
				$poss += $qi[$questions[$i]]['points'];
				if (($isreview && canimprove($i)) || (!$isreview && canimprovebest($i))) {
					echo ')';
				} else {
					echo ']';
				}
			}
			
			if ($current == $i) { echo "</span>";}
			
			echo "</li>\n";
		}
		echo "</ul>";
		if ($showeachscore) {
			if ($isreview) {
				echo "<p>", _('Review: ');
			} else {
				echo "<p>", _('Grade: ');
			}
			echo "$earned/$poss</p>";
		}
		if (!$isdiag && $testsettings['noprint']==0) {
			echo "<p><a href=\"#\" onclick=\"window.open('$imasroot/assessment/printtest.php','printver','width=400,height=300,toolbar=1,menubar=1,scrollbars=1,resizable=1,status=1,top=20,left='+(screen.width-420));return false;\">", _('Print Version'), "</a></p> ";
		}

		echo "</div>\n";
		return $todo;
	}
	
	function showscores($questions,$attempts,$testsettings) {
		global $isdiag,$allowregen,$isreview,$noindivscores,$scores,$bestscores,$qi,$superdone,$timelimitkickout, $reviewatend;
		
		$total = 0;
		$lastattempttotal = 0;
		for ($i =0; $i < count($bestscores);$i++) {
			if (getpts($bestscores[$i])>0) { $total += getpts($bestscores[$i]);}
			if (getpts($scores[$i])>0) { $lastattempttotal += getpts($scores[$i]);}
		}
		$totpossible = totalpointspossible($qi);
		$average = round(100*((float)$total)/((float)$totpossible),1);
			
		$doendredirect = false;
		$outmsg = '';
		if ($testsettings['endmsg']!='') {
			$endmsg = unserialize($testsettings['endmsg']);
			$redirecturl = '';
			if (isset($endmsg['msgs'])) {
				foreach ($endmsg['msgs'] as $sc=>$msg) { //array must be reverse sorted
					if (($endmsg['type']==0 && $total>=$sc) || ($endmsg['type']==1 && $average>=$sc)) {
						$outmsg = $msg;
						break;
					}
				}
				if ($outmsg=='') {
					$outmsg = $endmsg['def'];
				}
				if (!isset($endmsg['commonmsg'])) {$endmsg['commonmsg']='';}
					
				if (strpos($outmsg,'redirectto:')!==false) {
					$redirecturl = trim(substr($outmsg,11));
					echo "<input type=\"button\" value=\"", _('Continue'), "\" onclick=\"window.location.href='$redirecturl'\"/>";
					return false;
				}
			}
		}
		
		if ($isdiag) {
		  global $userid;
		  // SELECT#25
			$query = "SELECT * from imas_users WHERE id='$userid'";
			$result = mysql_query($query) or die("Query failed : " . mysql_error());
			$userinfo = mysql_fetch_array($result, MYSQL_ASSOC);
			echo "<h3>{$userinfo['LastName']}, {$userinfo['FirstName']}: ";
			echo substr($userinfo['SID'],0,strpos($userinfo['SID'],'~'));
			echo "</h3>\n";
		}
		
		echo "<h3>", _('Scores:'), "</h3>\n";
		
		if (!$noindivscores && !$reviewatend) {
			echo "<table class=scores>";
			for ($i=0;$i < count($scores);$i++) {
				echo "<tr><td>";
				if ($bestscores[$i] == -1) {
					$bestscores[$i] = 0;
				}
				if ($scores[$i] == -1) {
					$scores[$i] = 0;
					echo _('Question') . ' ' . ($i+1) . ': </td><td>';
					echo _('Last attempt: ');
					
					echo _('Not answered');
					echo "</td>";
					echo "<td>  ", _('Score in Gradebook: ');
					echo printscore($bestscores[$i],$i);
					echo "</td>";
					
					echo "</tr>\n";
				} else {
					echo _('Question') . ' ' . ($i+1) . ': </td><td>';
					echo _('Last attempt: ');
					
					echo printscore($scores[$i],$i);
					echo "</td>";
					echo "<td>  ", _('Score in Gradebook: ');
					echo printscore($bestscores[$i],$i);
					echo "</td>";
					
					echo "</tr>\n";
				}
			}
			echo "</table>";
		}
		global $testid;
		
		recordtestdata();
			
		if ($testsettings['testtype']!="NoScores") {
			
			echo "<p>", sprintf(_('Total Points on Last Attempts:  %d out of %d possible'), $lastattempttotal, $totpossible), "</p>\n";
			
			//if ($total<$testsettings['minscore']) {
			if (($testsettings['minscore']<10000 && $total<$testsettings['minscore']) || ($testsettings['minscore']>10000 && $total<($testsettings['minscore']-10000)/100*$totpossible)) {
				echo "<p><b>", sprintf(_('Total Points Earned:  %d out of %d possible: '), $total, $totpossible);
			} else {
				echo "<p><b>", sprintf(_('Total Points in Gradebook: %d out of %d possible: '), $total, $totpossible);
			}
			
			echo "$average % </b></p>\n";	
			
			if ($outmsg!='') {
				echo "<p style=\"color:red;font-weight: bold;\">$outmsg</p>";
				if ($endmsg['commonmsg']!='' && $endmsg['commonmsg']!='<p></p>') {
					echo $endmsg['commonmsg'];
				}
			}
			
			//if ($total<$testsettings['minscore']) {
			if (($testsettings['minscore']<10000 && $total<$testsettings['minscore']) || ($testsettings['minscore']>10000 && $total<($testsettings['minscore']-10000)/100*$totpossible)) {
				if ($testsettings['minscore']<10000) {
					$reqscore = $testsettings['minscore'];
				} else {
					$reqscore = ($testsettings['minscore']-10000).'%';
				}
				echo "<p><span style=\"color:red;\"><b>", sprintf(_('A score of %d is required to receive credit for this assessment'), $reqscore), "<br/>", _('Grade in Gradebook: No Credit (NC)'), "</span></p> ";	
			}
		} else {
			echo "<p><b>", _('Your scores have been recorded for this assessment.'), "</b></p>";
		}
		
		//if timelimit is exceeded
		$now = time();
		if (!$timelimitkickout && ($testsettings['timelimit']>0) && (($now-$GLOBALS['starttime']) > $testsettings['timelimit'])) {
			$over = $now-$GLOBALS['starttime'] - $testsettings['timelimit'];
			echo "<p>", _('Time limit exceeded by'), " ";
			if ($over > 60) {
				$overmin = floor($over/60);
				echo "$overmin ", _('minutes'), ", ";
				$over = $over - $overmin*60;
			}
			echo "$over ", _('seconds'), ".<br/>\n";
			echo _('Grade is subject to acceptance by the instructor'), "</p>\n";
		}
		
		
		if (!$superdone) { // $total < $totpossible && 
			if ($noindivscores) {
				echo "<p>", _('<a href="showtest.php?reattempt=all">Reattempt test</a> on questions allowed (note: where reattempts are allowed, all scores, correct and incorrect, will be cleared)'), "</p>";
			} else {
				if (canimproveany()) {
					echo "<p>", _('<a href="showtest.php?reattempt=canimprove">Reattempt test</a> on questions that can be improved where allowed'), "</p>";
				} 
				if (hasreattemptsany()) {
					echo "<p>", _('<a href="showtest.php?reattempt=all">Reattempt test</a> on all questions where allowed'), "</p>";
				}
			}
			
			if ($allowregen) {
				echo "<p>", _('<a href="showtest.php?regenall=missed">Try similar problems</a> for all questions with less than perfect scores where allowed.'), "</p>";
				echo "<p>", _('<a href="showtest.php?regenall=all">Try similar problems</a> for all questions where allowed.'), "</p>";
			}
		}
		if ($testsettings['testtype']!="NoScores") {
			$hascatset = false;
			foreach($qi as $qii) {
				if ($qii['category']!='0') {
					$hascatset = true;
					break;
				}
			}
			if ($hascatset) {
				include("../assessment/catscores.php");
				catscores($questions,$bestscores,$testsettings['defpoints'],$testsettings['defoutcome'],$testsettings['courseid']);
			}
		}
		if ($reviewatend) {
			global $testtype, $scores, $saenddate, $isteacher, $istutor, $seeds, $attempts;
			
			$showa=false;
			
			for ($i=0; $i<count($questions); $i++) {
				echo '<div>';
				if (!$noraw) {
					if (strpos($rawscores[$qn],'~')!==false) {
						$col = explode('~',$rawscores[$qn]);
					} else {
						$col = array($rawscores[$qn]);
					}
				} else {
					$col = scorestocolors($noraw?$scores[$qn]:$rawscores[$qn], $qi[$questions[$i]]['points'], $qi[$questions[$i]]['answeights'],$noraw);
				}
				displayq($i, $qi[$questions[$i]]['questionsetid'],$seeds[$i],$showa,false,$attempts[$i],false,false,false,$col);
				echo "<div class=review>", _('Question')." ".($i+1).". ", _('Last Attempt:');
				echo printscore($scores[$i], $i);

				echo '<br/>', _('Score in Gradebook: ');
				echo printscore($bestscores[$i],$i);
				echo '</div>';
			}
				
		}
		return true;
		
	}

	function endtest($testsettings) {
		
		//unset($sessiondata['sessiontestid']);
	}
	function leavetestmsg() {
		global $isdiag, $diagid, $isltilimited, $testsettings;
		if ($isdiag) {
			echo "<a href=\"../diag/index.php?id=$diagid\">", _('Exit Assessment'), "</a></p>\n";
		} else if ($isltilimited || $sessiondata['intreereader']) {
			
		} else {
			echo "<a href=\"../course/course.php?cid={$testsettings['courseid']}\">", _('Return to Course Page'), "</a></p>\n";
		}
	}
?>
