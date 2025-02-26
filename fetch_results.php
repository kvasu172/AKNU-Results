<?php

require 'vendor/autoload.php'; // Include Composer autoload for Guzzle and PhpSpreadsheet

use GuzzleHttp\Client;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Function to fetch results for a single hall ticket number
function fetchResult($url, $hallTicketNumber) {
    $client = new Client();
    try {
        // Send POST request with hall ticket number
        $response = $client->post($url, [
            'form_params' => [
                'keyword' => $hallTicketNumber
            ]
        ]);

        $html = $response->getBody()->getContents();
        $soup = str_get_html($html);

        // Check if the result table exists
        $studentDetailsTable = $soup->find('table.table.table-hover.table-striped.table-bordered');
        if (!$studentDetailsTable) {
            echo "No results found for Hall Ticket: $hallTicketNumber\n";
            return null;
        }

        // Extract student details
        $studentInfo = [];
        foreach ($studentDetailsTable->find('tr') as $row) {
            $cells = $row->find('td');
            if (count($cells) == 2) {
                $key = trim(str_replace(":", "", $cells[0]->plaintext));
                $value = trim($cells[1]->plaintext);
                $studentInfo[$key] = $value;
            }
        }

        // Extract grades
        $gradesTable = $soup->find('table.table.table-hover.table-striped.table-bordered', 1);
        $grades = [];
        foreach ($gradesTable->find('tr') as $row) {
            $cells = $row->find('td');
            if (count($cells) == 2) {
                $subject = trim($cells[0]->plaintext);
                $grade = trim($cells[1]->plaintext);
                $grades[] = ['Subject Name' => $subject, 'Grade' => $grade];
            }
        }

        $studentInfo['Grades'] = $grades;
        $studentInfo['Hall Ticket'] = $hallTicketNumber;
        echo "Successfully fetched results for Hall Ticket: $hallTicketNumber\n";

        return $studentInfo;
    } catch (Exception $e) {
        echo "Error fetching result for Hall Ticket: $hallTicketNumber, Error: " . $e->getMessage() . "\n";
        return null;
    }
}

// Function to process the uploaded CSV file
function processCsvFile($filePath, $url) {
    if (!file_exists($filePath)) {
        echo "File not found: $filePath\n";
        return null;
    }

    $hallTickets = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        while (($data = fgetcsv($handle)) !== false) {
            $hallTickets[] = $data[0]; // Assuming the hall ticket numbers are in the first column
        }
        fclose($handle);
    } else {
        echo "Error reading CSV file.\n";
        return null;
    }

    $results = [];
    foreach ($hallTickets as $hallTicket) {
        $hallTicket = trim($hallTicket);
        if (!empty($hallTicket)) {
            $result = fetchResult($url, $hallTicket);
            if ($result) {
                $results[] = $result;
            }
            sleep(2); // Add delay to avoid overloading the server
        }
    }

    return $results;
}

// Function to save results to an Excel file
function saveResultsToExcel($results, $outputFile = 'results.xlsx') {
    if (empty($results)) {
        echo "No results to save.\n";
        return;
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Set headers
    $headers = ['Register Number', 'Student Name', 'Course Name', 'Semester'];
    $subjectHeaders = [];
    foreach ($results[0]['Grades'] as $grade) {
        $subjectHeaders[] = $grade['Subject Name'];
    }
    $headers = array_merge($headers, $subjectHeaders);
    $sheet->fromArray([$headers], null, 'A1');

    // Add data
    $row = 2;
    foreach ($results as $student) {
        $studentRow = [
            $student['Register Number'] ?? '',
            $student['Student Name'] ?? '',
            $student['Course Name'] ?? '',
            $student['Semester'] ?? ''
        ];
        foreach ($student['Grades'] as $grade) {
            $studentRow[] = $grade['Grade'];
        }
        $sheet->fromArray([$studentRow], null, "A$row");
        $row++;
    }

    // Save to file
    try {
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputFile);
        echo "Results saved to $outputFile\n";
    } catch (Exception $e) {
        echo "Error saving results to file: " . $e->getMessage() . "\n";
    }
}

// Main function to handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['url']) && isset($_FILES['csv_file'])) {
        $url = $_POST['url'];
        $csvFile = $_FILES['csv_file'];

        // Validate uploaded file
        if ($csvFile['error'] === UPLOAD_ERR_OK) {
            $filePath = $csvFile['tmp_name'];
            $results = processCsvFile($filePath, $url);

            if ($results) {
                saveResultsToExcel($results);
            }
        } else {
            echo "Error uploading file.\n";
        }
    } else {
        echo "Please provide both the URL and a CSV file.\n";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fetch Results</title>
</head>
<body>
    <h1>Fetch Student Results</h1>
    <form method="POST" enctype="multipart/form-data">
        <label for="url">Results Page URL:</label><br>
        <input type="text" id="url" name="url" required><br><br>

        <label for="csv_file">Upload CSV File:</label><br>
        <input type="file" id="csv_file" name="csv_file" accept=".csv" required><br><br>

        <button type="submit">Fetch Results</button>
    </form>
</body>
</html>