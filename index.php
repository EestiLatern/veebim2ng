<?php
session_start();

function generateCode($length=5){
    $chars='ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code='';
    for($i=0;$i<$length;$i++){
        $code.=$chars[rand(0,strlen($chars)-1)];
    }
    return $code;
}

function normalizeText($text) {
    if (empty($text)) return '';
    
    $text = mb_strtolower($text, 'UTF-8');
    
    $replacements = [
        'ä' => 'a',
        'ö' => 'o',
        'õ' => 'o', 
        'ü' => 'u',
        'š' => 's',
        'ž' => 'z',
        'č' => 'c'
    ];
    
    foreach ($replacements as $search => $replace) {
        $text = str_replace($search, $replace, $text);
    }
    
    $text = trim($text);
    
    return $text;
}

function isInfoQuestion($question) {
    return isset($question['type']) && $question['type'] === 'info';
}

function formatDateToDisplay($date) {
    if (empty($date)) return '';
    
    $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y'];
    
    foreach ($formats as $format) {
        $parsedDate = date_create_from_format($format, $date);
        if ($parsedDate !== false) {
            return $parsedDate->format('d.m.Y');
        }
    }
    
    $timestamp = strtotime($date);
    if ($timestamp !== false) {
        return date('d.m.Y', $timestamp);
    }
    
    return $date;
}

function formatDateToInput($date) {
    if (empty($date)) return '';
    
    $formats = ['d.m.Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'];
    
    foreach ($formats as $format) {
        $parsedDate = date_create_from_format($format, $date);
        if ($parsedDate !== false) {
            return $parsedDate->format('Y-m-d');
        }
    }
    
    $timestamp = strtotime($date);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }
    
    return $date;
}

function saveAnswerToFile($data) {
    $filename = 'vastused.json';
    $allAnswers = [];
    
    if(file_exists($filename)) {
        $content = file_get_contents($filename);
        $allAnswers = json_decode($content, true) ?? [];
    }
    
    $allAnswers[] = $data;
    file_put_contents($filename, json_encode($allAnswers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    return file_exists($filename);
}

function sortQuestionsByOrder($questions) {
    $sortedQuestions = [];
    
    foreach($questions as $id => $question) {
        if(isset($question['order']) && $question['order'] === 'id-fixed') {
            $sortedQuestions[$id] = $question;
        }
    }
    
    $currentPosition = 0;
    foreach($questions as $id => $question) {
        if(isset($question['order']) && $question['order'] === 'fixed') {
            while(isset($sortedQuestions[$currentPosition])) {
                $currentPosition++;
            }
            $sortedQuestions[$currentPosition] = $question;
            $currentPosition++;
        }
    }
    
    foreach($questions as $id => $question) {
        if(!isset($question['order']) || $question['order'] == 0) {
            while(isset($sortedQuestions[$currentPosition])) {
                $currentPosition++;
            }
            $sortedQuestions[$currentPosition] = $question;
            $currentPosition++;
        }
    }
    
    ksort($sortedQuestions);
    return $sortedQuestions;
}

$questions = [];
$questionsFile = 'kysimused.json';
if(file_exists($questionsFile)){
    $questionsData = file_get_contents($questionsFile);
    $questions = json_decode($questionsData, true);
    if(json_last_error() !== JSON_ERROR_NONE){
        die('Viga küsimuste faili laadimisel: ' . json_last_error_msg());
    }
    
    $questions = sortQuestionsByOrder($questions);
}

if(!isset($_SESSION['level'])) {
    $_SESSION['level'] = 0;
}
if(!isset($_SESSION['answers'])) {
    $_SESSION['answers'] = [];
}
if(!isset($_SESSION['current_answered'])) {
    $_SESSION['current_answered'] = false;
}

$error='';
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['start'])){
    $eesnimi=htmlspecialchars(trim($_POST['eesnimi']));
    $perenimi=htmlspecialchars(trim($_POST['perenimi']));
    $email=filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
    
    if(empty($eesnimi) || empty($perenimi) || !$email){
        $error="Palun täida kõik väljad korrektselt!";
    } else {
        $osalejad=$_POST['osalejad'];
        $playerData=[
            'eesnimi'=>$eesnimi,
            'perenimi'=>$perenimi,
            'email'=>$email,
            'osalejad'=>$osalejad,
            'start_time'=>date('Y-m-d H:i:s')
        ];
        
        if($osalejad!='üksinda'){
            $mitu=intval($_POST['mitu']??1);
            if($mitu<1 || $mitu>7){
                $error="Ühes tiimis saab olla maksimaalselt 7 inimest!";
            } else{
                $playerData['mitu']=$mitu;
            }
        }
        
        if(empty($error)){
            $_SESSION['player']=$playerData;
            $_SESSION['kood']=generateCode();
            $_SESSION['level']=0;
            $_SESSION['answers']=[];
            $_SESSION['current_answered']=false;
            $_SESSION['game_start_time'] = date('Y-m-d H:i:s');
            
            $gameData = [
                'Mängija' => $eesnimi . ' ' . $perenimi,
                'E-post' => $email,
                'Sessioon' => $_SESSION['kood'],
                'Osalemine' => $osalejad . ($osalejad != 'üksinda' ? ' (' . ($playerData['mitu'] ?? 1) . ' inimest)' : ''),
                'Tegevusaeg_algus' => date('Y-m-d H:i:s'),
                'Tegevusaeg_lõpp' => '',
                'süsteemne' => [
                    'type' => 'game_start',
                    'code' => $_SESSION['kood'],
                    'start_time' => date('Y-m-d H:i:s')
                ]
            ];
            saveAnswerToFile($gameData);
            
            ob_start();
            header("Location: " . $_SERVER['PHP_SELF']);
            ob_end_flush();
            exit();
        }
    }
}

if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['submit_answer'])){
    $currentLevel = $_SESSION['level'] ?? 0;
    $currentQuestion = $questions[$currentLevel] ?? null;
    
    if($currentQuestion && !isInfoQuestion($currentQuestion)){
        $userAnswer = $_POST['answer'] ?? [];
        
        if($currentQuestion['type'] == 'kuupäev' && !empty($userAnswer[0])) {
            $userAnswer[0] = formatDateToInput($userAnswer[0]);
        }
        
        $_SESSION['answers'][$currentLevel] = [
            'question_id' => $currentQuestion['id'] ?? $currentLevel,
            'question' => $currentQuestion['question'] ?? '',
            'user_answer' => $userAnswer,
            'correct_answer' => $currentQuestion['correct'] ?? [],
            'time' => date('Y-m-d H:i:s')
        ];
        
        $_SESSION['current_answered'] = true;
        
        $answerData = [
            'Küsimus' => $currentQuestion['question'] ?? '',
            'Küsimuse_väärtus' => $currentQuestion['value'] ?? ($currentQuestion['id'] ?? $currentLevel),
            'Õige_vastus' => $currentQuestion['correct'] ?? [],
            'Mängija_vastus' => $userAnswer,
            'Vastamise_aeg' => date('Y-m-d H:i:s'),
            'süsteemne' => [
                'type' => 'answer',
                'code' => $_SESSION['kood'] ?? '',
                'question_id' => $currentQuestion['id'] ?? $currentLevel,
                'level' => $currentLevel
            ]
        ];
        saveAnswerToFile($answerData);
    }
    
    ob_start();
    header("Location: " . $_SERVER['PHP_SELF']);
    ob_end_flush();
    exit();
}

if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['next'])){
    $_SESSION['level']++;
    $_SESSION['current_answered'] = false;
    
    if($_SESSION['level'] >= count($questions)){
        $endData = [
            'Mängija' => $_SESSION['player']['eesnimi'] . ' ' . $_SESSION['player']['perenimi'],
            'E-post' => $_SESSION['player']['email'],
            'Sessioon' => $_SESSION['kood'],
            'Osalemine' => $_SESSION['player']['osalejad'] . 
                          ($_SESSION['player']['osalejad'] != 'üksinda' ? 
                           ' (' . ($_SESSION['player']['mitu'] ?? 1) . ' inimest)' : ''),
            'Tegevusaeg_algus' => $_SESSION['game_start_time'] ?? date('Y-m-d H:i:s'),
            'Tegevusaeg_lõpp' => date('Y-m-d H:i:s'),
            'Kokku_küsimusi' => count($questions),
            'süsteemne' => [
                'type' => 'game_end',
                'end_time' => date('Y-m-d H:i:s'),
                'completed' => true
            ]
        ];
        saveAnswerToFile($endData);
    }
    
    ob_start();
    header("Location: " . $_SERVER['PHP_SELF']);
    ob_end_flush();
    exit();
}

$level=$_SESSION['level']??0;
$currentQuestion=$questions[$level]??null;
$currentAnswered = $_SESSION['current_answered'] ?? false;
$totalQuestions = count($questions);

$showWelcome = !isset($_SESSION['player']);
$showGame = isset($_SESSION['player']) && isset($currentQuestion);
$showEnd = isset($_SESSION['player']) && !$showGame;
?>
<!DOCTYPE html>
<html lang="et">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nõmme kohalik seiklusmäng</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
<?php if($showWelcome): ?>
    <div class="welcome-screen">
        <h1>🌲 Tere tulemast Nõmme mängu! 🌲</h1>
        <p class="welcome-text">
            tiiteltekst 1<br>
            tiiteltekst 2
        </p>

        <?php if($error): ?>
            <div class="error-message"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="post" class="start-form">
            <input type="hidden" name="start" value="1">
            
            <div class="form-group">
                <label for="eesnimi">Eesnimi:</label>
                <input type="text" id="eesnimi" name="eesnimi" required>
            </div>
            
            <div class="form-group">
                <label for="perenimi">Perenimi:</label>
                <input type="text" id="perenimi" name="perenimi" required>
            </div>
            
            <div class="form-group">
                <label for="email">E-post:</label>
                <input type="email" id="email" name="email" required>
            </div>
            
            <div class="form-group">
                <label for="osalejad">Kellega mängid?</label>
                <select id="osalejad" name="osalejad" required 
                        onchange="document.getElementById('mituDiv').style.display=this.value!='üksinda'?'block':'none'">
                    <option value="">Vali variant</option>
                    <option value="üksinda">Üksinda</option>
                    <option value="perega">Perega</option>
                    <option value="sõpradega">Sõpradega</option>
                    <option value="kellegimuuga">Kellegi teisega</option>
                </select>
            </div>
            
            <div id="mituDiv" style="display:none;" class="form-group">
                <label for="mitu">Mitu mängijat? (ühes tiimis võib olla kuni 7 inimest!)</label>
                <input type="number" id="mitu" name="mitu" min="1" max="7" value="2">
            </div>
            
            <button type="submit" class="start-button">Alusta mängu!</button>
        </form>
        
        <div class="game-info">
            <h3>Mängu reeglid:</h3>
            <ul>
                <li>Sisesta oma andmed ja vali, kellega mängid.</li>
                <li>Otsi vastuseid Nõmmel ringi rännates (mängu jooksul on vajalik bussisõit ja rongisõit).</li>
                <li>Kui tead juba vastust, võid selle juba kirja panna, ning võid jätte ühe punkti vahelt käimata.</li>
                <li>Lõbutse ja ära tee sohki!</li>
            </ul>
        </div>
    </div>

<?php elseif($showGame && $currentQuestion): ?>
    <?php if(isInfoQuestion($currentQuestion)): ?>
        <div class="info-screen">
            <h2 class="info-title"><?php echo $currentQuestion['title'] ?? ''; ?></h2>
            
            <?php if(isset($currentQuestion['image']) && !empty($currentQuestion['image'])): ?>
                <div class="question-image">
                    <img src="<?php echo htmlspecialchars($currentQuestion['image']); ?>" 
                         alt="pilt" 
                         class="question-img">
                </div>
            <?php endif; ?>
            
            <div class="info-content">
                <?php echo nl2br(htmlspecialchars($currentQuestion['content'] ?? '')); ?>
            </div>
            
            <form method="post">
                <button type="submit" name="next" class="next-button">
                    <?php echo ($level + 1 < $totalQuestions) ? 'Jätka →' : 'Lõpeta mäng'; ?>
                </button>
            </form>
        </div>
    <?php else: ?>
        <div class="game-screen">
            <div class="progress-bar">
                <div class="progress" style="width: <?php echo ($level / $totalQuestions * 100); ?>%"></div>
                <div class="progress-text">Küsimus <?php echo $level + 1; ?> / <?php echo $totalQuestions; ?></div>
            </div>
            
            <div class="question-container">
                <h2 class="question-title">Küsimus <?php echo $level + 1; ?></h2>
                
                <p class="question-text"><?php echo nl2br(htmlspecialchars($currentQuestion['question'] ?? '')); ?></p>

                <?php if(isset($currentQuestion['image']) && !empty($currentQuestion['image'])): ?>
                    <div class="question-image">
                        <img src="<?php echo htmlspecialchars($currentQuestion['image']); ?>" 
                             alt="Küsimuse pilt" 
                             class="question-img">
                    </div>
                <?php endif; ?>
                            
                <?php if(!$currentAnswered): ?>
                    <form method="post" id="answerForm">
                        <input type="hidden" name="submit_answer" value="1">
                        <div class="answers-container">
                        <?php
                        $type = $currentQuestion['type'] ?? 'valik';
                        $options = $currentQuestion['options'] ?? [];
                        
                        if($type == 'valik'){
                            foreach($options as $index => $opt){
                                echo "<label class='answer-option'>
                                        <input type='radio' name='answer[]' value='" . htmlspecialchars($opt) . "' required>
                                        <span class='answer-text'>{$opt}</span>
                                      </label>";
                            }
                        } elseif($type == 'mitmikvalik'){
                            foreach($options as $index => $opt){
                                echo "<label class='answer-option'>
                                        <input type='checkbox' name='answer[]' value='" . htmlspecialchars($opt) . "'>
                                        <span class='answer-text'>{$opt}</span>
                                      </label>";
                            }
                        } elseif($type == 'tekst'){
                            echo "<input type='text' name='answer[]' placeholder='Sisesta vastus...' class='text-answer' required>";
                        } elseif($type == 'kuupäev'){
                            echo "<div class='date-input-container'>
                                    <input type='text' name='answer[]' placeholder='pp.kk.aaaa' class='text-answer' id='dateInput' required>
                                    <div class='date-format-hint'>Sisesta kuupäev kujul pp.kk.aaaa</div>
                                  </div>";
                        }
                        ?>
                        </div>
                        
                        <button type="submit" class="check-button">Kontrolli vastust</button>
                    </form>
                <?php else: ?>
                    <div class="answers-container">
                    <?php
                    $type = $currentQuestion['type'] ?? 'valik';
                    $options = $currentQuestion['options'] ?? [];
                    $correctAnswers = (array)($currentQuestion['correct'] ?? []);
                    $userAnswers = $_SESSION['answers'][$level]['user_answer'] ?? [];
                    
                    $displayCorrectAnswers = [];
                    foreach($correctAnswers as $correctAnswer) {
                        if ($type == 'kuupäev') {
                            $displayCorrectAnswers[] = formatDateToDisplay($correctAnswer);
                        } else {
                            $displayCorrectAnswers[] = $correctAnswer;
                        }
                    }
                    
                    $isCorrect = false;
                    
                    if($type == 'valik' || $type == 'mitmikvalik') {
                        $userAnswersSorted = $userAnswers;
                        $correctAnswersSorted = $correctAnswers;
                        sort($userAnswersSorted);
                        sort($correctAnswersSorted);
                        $isCorrect = $userAnswersSorted == $correctAnswersSorted;
                    } elseif($type == 'tekst' || $type == 'kuupäev') {
                        if ($type == 'kuupäev') {
                            $userAnswerFormatted = !empty($userAnswers[0]) ? formatDateToInput($userAnswers[0]) : '';
                            $isCorrect = in_array($userAnswerFormatted, $correctAnswers);
                        } elseif ($type == 'tekst') {
                            $userAnswerNormalized = !empty($userAnswers[0]) ? normalizeText($userAnswers[0]) : '';
                            
                            $isCorrect = false;
                            foreach ($correctAnswers as $correctAnswer) {
                                if (normalizeText($correctAnswer) === $userAnswerNormalized) {
                                    $isCorrect = true;
                                    break;
                                }
                            }
                        }
                    }
                    
                    if($type == 'valik'){
                        foreach($options as $index => $opt){
                            $checked = in_array($opt, $userAnswers) ? 'checked' : '';
                            $isOptionCorrect = in_array($opt, $correctAnswers);
                            $resultClass = $isOptionCorrect ? 'correct' : (in_array($opt, $userAnswers) ? 'wrong' : '');
                            
                            echo "<label class='answer-option {$resultClass}'>
                                    <input type='radio' disabled {$checked}>
                                    <span class='answer-text'>{$opt}</span>
                                  </label>";
                        }
                    } elseif($type == 'mitmikvalik'){
                        foreach($options as $index => $opt){
                            $checked = in_array($opt, $userAnswers) ? 'checked' : '';
                            $isOptionCorrect = in_array($opt, $correctAnswers);
                            $resultClass = $isOptionCorrect ? 'correct' : ((in_array($opt, $userAnswers) && !$isOptionCorrect) ? 'wrong' : '');
                            
                            echo "<label class='answer-option {$resultClass}'>
                                    <input type='checkbox' disabled {$checked}>
                                    <span class='answer-text'>{$opt}</span>
                                  </label>";
                        }
                    } elseif($type == 'tekst'){
                        $value = $userAnswers[0] ?? '';
                        $resultClass = $isCorrect ? 'correct' : 'wrong';
                        echo "<input type='text' value='" . htmlspecialchars($value) . "' readonly class='text-answer {$resultClass}'>";
                    } elseif($type == 'kuupäev'){
                        $value = !empty($userAnswers[0]) ? formatDateToDisplay($userAnswers[0]) : '';
                        $resultClass = $isCorrect ? 'correct' : 'wrong';
                        echo "<input type='text' value='{$value}' readonly class='date-answer-display {$resultClass}'>";
                    }
                    ?>
                    </div>
                    
                    <div class="answer-feedback">
                        <p class="feedback-text <?php echo $isCorrect ? 'correct-feedback' : 'wrong-feedback'; ?>">
                            <?php echo $isCorrect ? '✅ Vastus on õige!' : '❌ Vastus on vale!'; ?>
                        </p>
                        
                        <?php if(!$isCorrect && !empty($displayCorrectAnswers)): ?>
                            <p class="correct-answer-text">
                                Õige vastus: <?php echo implode(', ', $displayCorrectAnswers); ?>
                            </p>
                        <?php endif; ?>
                        
                        <form method="post">
                            <button type="submit" name="next" class="next-button">
                                <?php echo ($level + 1 < $totalQuestions) ? 'Järgmine küsimus →' : 'Lõpeta mäng'; ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="player-info">
                Mängija: <strong><?php echo htmlspecialchars($_SESSION['player']['eesnimi'] ?? ''); ?> <?php echo htmlspecialchars($_SESSION['player']['perenimi'] ?? ''); ?></strong>
            </div>
        </div>
    <?php endif; ?>

<?php elseif($showEnd): ?>
    <div class="end-screen">
        <h1 class="congratulations">🎉 Palju õnne! 🎉</h1>        
        <div class="result-card">
            <h2>Sinu tulemused:</h2>
            
            <?php
            $correctCount = 0;
            $totalAnswerableQuestions = 0; 
            $answers = $_SESSION['answers'] ?? [];

            
            foreach($answers as $index => $answerData){
                $question = $questions[$index] ?? null;
                if($question && !isInfoQuestion($question)){
                    $totalAnswerableQuestions++;
                    $userAnswer = $answerData['user_answer'] ?? [];
                    $correctAnswer = $question['correct'] ?? [];
                    
                    $isCorrect = false;
                    if($question['type'] == 'valik' || $question['type'] == 'mitmikvalik'){
                        sort($userAnswer);
                        sort($correctAnswer);
                        $isCorrect = $userAnswer == $correctAnswer;
                    } elseif($question['type'] == 'tekst' || $question['type'] == 'kuupäev'){
                        if ($question['type'] == 'kuupäev') {
                            $userAnswerFormatted = !empty($userAnswer[0]) ? formatDateToInput($userAnswer[0]) : '';
                            $isCorrect = in_array($userAnswerFormatted, $correctAnswer);
                        } elseif ($question['type'] == 'tekst') {
                            $userAnswerNormalized = !empty($userAnswer[0]) ? normalizeText($userAnswer[0]) : '';
                            $isCorrect = false;
                            foreach ($correctAnswer as $correct) {
                                if (normalizeText($correct) === $userAnswerNormalized) {
                                    $isCorrect = true;
                                    break;
                                }
                            }
                        }
                    }
                    
                    if($isCorrect) $correctCount++;
                }
            }
            
            $percentage = $totalAnswerableQuestions > 0
                ? round(($correctCount / $totalAnswerableQuestions) * 100)
                : 0;
            ?>
            
            <div class="score-display">
                <div class="score-circle">
                    <span class="score-percentage"><?php echo $percentage; ?>%</span>
                    <span class="score-text">Õiget vastust</span>
                </div>
                <div class="score-details">
                    <p><strong><?php echo $correctCount; ?></strong> / <strong><?php echo $totalAnswerableQuestions; ?></strong> õiget vastust</p>
                    <p>Mängija: <strong><?php echo htmlspecialchars($_SESSION['player']['eesnimi'] ?? ''); ?> <?php echo htmlspecialchars($_SESSION['player']['perenimi'] ?? ''); ?></strong></p>
                    <p>Osalemine: <strong><?php echo htmlspecialchars($_SESSION['player']['osalejad'] ?? ''); ?></strong></p>
                    <?php if(isset($_SESSION['player']['mitu']) && $_SESSION['player']['osalejad'] != 'üksinda'): ?>
                        <p>Mängijate arv: <strong><?php echo $_SESSION['player']['mitu']; ?></strong></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="action-buttons">
            <button onclick="window.print()" class="print-button">Prindi tulemus</button>
        </div>
    </div>
<?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var textAnswer = document.querySelector('input[type="text"]');
    if(textAnswer && !textAnswer.readOnly) {
        textAnswer.focus();
    }
    
    var dateInput = document.getElementById('dateInput');
    if(dateInput) {
        dateInput.addEventListener('input', function(e) {
            var value = e.target.value.replace(/\D/g, '');
            
            if(value.length > 2 && value.length <= 4) {
                value = value.substring(0,2) + '.' + value.substring(2);
            } else if(value.length > 4) {
                value = value.substring(0,2) + '.' + value.substring(2,4) + '.' + value.substring(4,8);
            }
            
            e.target.value = value;
        });
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var textAnswer = document.querySelector('input[type="text"]');
    if(textAnswer && !textAnswer.readOnly) {
        textAnswer.focus();
    }

    var dateInput = document.getElementById('dateInput');
    if(dateInput) {
        dateInput.addEventListener('input', function(e) {
            var value = e.target.value.replace(/\D/g, '');
            
            if(value.length > 2 && value.length <= 4) {
                value = value.substring(0,2) + '.' + value.substring(2);
            } else if(value.length > 4) {
                value = value.substring(0,2) + '.' + value.substring(2,4) + '.' + value.substring(4,8);
            }
            
            e.target.value = value;
        });
    }
});
</script>

<footer class="footer">
    <div class="footer-content">
        <p>
            © <?php echo date('Y'); ?> KiviSync
        </p>
        <p>
            <a href="https://github.com/EestiLatern/veebim2ng" target="_blank" class="github-link">
                <img src="https://github.githubassets.com/images/modules/logos_page/GitHub-Mark.png" 
                     alt="GitHub Logo" class="github-logo">
                Avatud lähekood GitHub's
            </a>
        </p>
    </div>
</footer>

<style>
.footer {
    margin-top: 20px;
    padding: 15px 10px;
    text-align: center;
    font-size: 14px;
    color: rgba(255,255,255,0.85);
}

.footer a {
    color: white;
    font-weight: bold;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.footer a:hover {
    text-decoration: underline;
}

.github-logo {
    width: 20px;
    height: 20px;
}
</style>

</body>
</html>
